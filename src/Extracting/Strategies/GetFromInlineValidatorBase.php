<?php

namespace Knuckles\Scribe\Extracting\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\MethodAstParser;
use Knuckles\Scribe\Extracting\ParsesValidationRules;
use Knuckles\Scribe\Extracting\Shared\ValidationRulesFinders\RequestValidate;
use Knuckles\Scribe\Extracting\Shared\ValidationRulesFinders\RequestValidateFacade;
use Knuckles\Scribe\Extracting\Shared\ValidationRulesFinders\ThisValidate;
use Knuckles\Scribe\Extracting\Shared\ValidationRulesFinders\ValidatorMake;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;

class GetFromInlineValidatorBase extends Strategy
{
    use ParsesValidationRules;

    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if (! $endpointData->method instanceof \ReflectionMethod) {
            return [];
        }

        $methodAst = MethodAstParser::getMethodAst($endpointData->method);
        [$validationRules, $customParameterData] = $this->lookForInlineValidationRules($methodAst);

        $bodyParametersFromValidationRules = $this->getParametersFromValidationRules($validationRules, $customParameterData);

        return $this->normaliseArrayAndObjectParameters($bodyParametersFromValidationRules);
    }

    public function lookForInlineValidationRules(ClassMethod $methodAst): array
    {
        // Validation usually happens early on, so let's assume it's in the first 10 statements
        $statements = array_slice($methodAst->stmts, 0, 10);

        [$index, $validationStatement, $validationRules] = $this->findValidationExpression($statements);

        if ($validationStatement
            && ! $this->isValidationStatementMeantForThisStrategy($validationStatement)) {
            return [[], []];
        }

        // If validation rules were saved in a variable (like $rules),
        // try to find the var and expand the value
        if ($validationRules instanceof Node\Expr\Variable) {
            foreach (array_reverse(array_slice($statements, 0, $index)) as $earlierStatement) {
                if (
                    $earlierStatement instanceof Node\Stmt\Expression
                    && $earlierStatement->expr instanceof Node\Expr\Assign
                    && $earlierStatement->expr->var instanceof Node\Expr\Variable
                    && $earlierStatement->expr->var->name === $validationRules->name
                ) {
                    $validationRules = $earlierStatement->expr->expr;

                    break;
                }
            }
        }

        if (! $validationRules instanceof Node\Expr\Array_) {
            return [[], []];
        }

        // Turn rules Scribe cannot read from the AST (function calls, rule objects) into strings
        foreach ($validationRules->items as $item) {
            /** @var Node\ArrayItem $item */
            if ($item->value instanceof Node\Expr\Array_) {
                $item->value = $this->parseArrayRules($item->value);
            }
        }

        $rules = [];
        $customParameterData = [];
        foreach ($validationRules->items as $item) {
            /** @var Node\ArrayItem $item */
            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $paramName = $item->key->value;

            // Might be an expression or concatenated string, etc.
            // For now, let's focus on simple strings and arrays of strings
            if ($item->value instanceof Node\Scalar\String_) {
                $rules[$paramName] = $item->value->value;
            } elseif ($item->value instanceof Node\Expr\Array_) {
                $rulesList = [];
                foreach ($item->value->items as $arrayItem) {
                    /** @var Node\ArrayItem $arrayItem */
                    if ($arrayItem->value instanceof Node\Scalar\String_) {
                        $rulesList[] = $arrayItem->value->value;
                    }
                    // Try to extract Enum rule
                    elseif (
                        ($enum = $this->extractEnumClassFromArrayItem($arrayItem))
                        && enum_exists($enum) && method_exists($enum, 'tryFrom')
                    ) {
                        // $case->value only exists on BackedEnums, not UnitEnums
                        // method_exists($enum, 'tryFrom') implies the enum is a BackedEnum
                        // @phpstan-ignore-next-line
                        $rulesList[] = 'in:'.implode(',', array_map(fn ($case) => $case->value, $enum::cases()));
                    }
                }
                $rules[$paramName] = implode('|', $rulesList);
            } else {
                $rules[$paramName] = [];
            }

            $dataFromComment = [];
            $comments = implode("\n", array_map(
                fn ($comment) => mb_ltrim(mb_ltrim($comment->getReformattedText(), '/')),
                $item->getComments()
            ));

            if ($comments) {
                if (str_contains($comments, 'No-example')) {
                    $dataFromComment['example'] = null;
                }

                $dataFromComment['description'] = mb_trim(str_replace(['No-example.', 'No-example'], '', $comments));
                if (preg_match('/(.*\s+|^)Example:\s*([\s\S]+)\s*/s', $dataFromComment['description'], $matches)) {
                    $dataFromComment['description'] = mb_trim($matches[1]);
                    $dataFromComment['example'] = $matches[2];
                }
            }

            $customParameterData[$paramName] = $dataFromComment;
        }

        return [$rules, $customParameterData];
    }

    protected function parseArrayRules(Node\Expr\Array_ $rules): Node\Expr\Array_
    {
        foreach ($rules->items as $item) {
            $rule = $item->value;

            if ($rule instanceof Node\Expr\FuncCall) {
                $item->value = $this->parseFunctionRule($rule);
            } elseif ($rule instanceof Node\Expr\MethodCall) {
                if ($rule->var instanceof Node\Expr\StaticCall
                    && $rule->var->class instanceof Node\Name
                    && $rule->var->class->getLast() === 'Rule'
                    && $rule->var->name instanceof Node\Identifier
                    && $rule->var->name->name === 'unique'
                ) {
                    $item->value = new Node\Scalar\String_('unique', $rule->getAttributes());
                }

                //                $parsedRule = $this->parseRuleMethodRule($rule);
                //                if ($parsedRule !== null) {
                //                    $item->value = $parsedRule;
                //                }
            } elseif ($rule instanceof Node\Expr\New_ && $this->extractEnumClassFromArrayItem($item) === null) {
                $item->value = $this->parseClassBasedRule($rule);
            }
        }

        return $rules;
    }

    protected function parseFunctionRule(Node\Expr\FuncCall $rule): Node\Scalar\String_
    {
        if ($rule->name instanceof Node\Name && $rule->name->getLast() === 'sprintf') {
            $args = [];

            foreach ($rule->getArgs() as $arg) {
                $arg = $arg->value;
                if ($arg instanceof Node\Scalar\String_) {
                    $args[] = $arg->value;
                } elseif ($arg instanceof Node\Expr\ClassConstFetch
                    && $arg->class instanceof Node\Name
                    && $arg->name instanceof Node\Identifier
                ) {
                    $className = $arg->class->getAttribute('resolvedName')?->toString() ?? $arg->class->toString();
                    $constName = $arg->name->name;
                    // If short class name, try to resolve to FQCN via declared classes
                    if (! class_exists($className)) {
                        foreach (get_declared_classes() as $declaredClass) {
                            if ($declaredClass === $className || str_ends_with($declaredClass, '\\'.$className)) {
                                $className = $declaredClass;

                                break;
                            }
                        }
                    }

                    try {
                        $constant = constant(sprintf('%s::%s', $className, $constName));
                    } catch (\Throwable) {
                        continue;
                    }
                    $args[] = $constant;
                }
            }

            return new Node\Scalar\String_(sprintf(...$args), $rule->getAttributes());
        }

        // Any other call cannot be evaluated from the AST, so it becomes a placeholder Scribe
        // ignores rather than stopping the run. Same reasoning as parseClassBasedRule().
        return new Node\Scalar\String_(
            'call:'.($rule->name instanceof Node\Name ? $rule->name->getLast() : 'expression'),
            $rule->getAttributes()
        );
    }

    /**
     * Replace a chained rule (`Rule::unique(...)->ignore(...)`) with a placeholder.
     *
     * Not currently reached — the caller is commented out and `Rule::unique()` chains are handled
     * inline in parseArrayRules() — but kept so re-enabling it cannot stop a generate run.
     */
    protected function parseRuleMethodRule(Node\Expr\MethodCall $rule): ?Node\Scalar\String_
    {
        $method = $rule->name instanceof Node\Identifier ? $rule->name->name : 'expression';

        return new Node\Scalar\String_('method:'.$method, $rule->getAttributes());
    }

    /**
     * Replace a class-based rule (`new SomeRule`) with a placeholder Scribe can read past.
     *
     * A rule object carries its logic in PHP, so there is nothing here to turn into a documented
     * constraint — the parameter is described by the plain rules next to it instead. An
     * unrecognised rule string is ignored by the rule parser, which is exactly what we want.
     *
     * The name is prefixed so it can never collide with a real rule: Scribe splits a rule string on
     * ":" into name and arguments, and there is no Laravel rule called "class".
     */
    protected function parseClassBasedRule(Node\Expr\New_ $rule): Node\Scalar\String_
    {
        $className = $rule->class instanceof Node\Name
            ? $rule->class->getLast()
            : 'anonymous';

        return new Node\Scalar\String_('class:'.$className, $rule->getAttributes());
    }

    protected function extractEnumClassFromArrayItem(Node\ArrayItem $arrayItem): ?string
    {
        $args = [];

        // Enum rule with the form "new Enum(...)"
        if ($arrayItem->value instanceof Node\Expr\New_
            && $arrayItem->value->class instanceof Node\Name
            && str_ends_with($arrayItem->value->class->name, 'Enum')
        ) {
            $args = $arrayItem->value->args;
        }

        // Enum rule with the form "Rule::enum(...)"
        elseif ($arrayItem->value instanceof Node\Expr\StaticCall
            && $arrayItem->value->class instanceof Node\Name
            && str_ends_with($arrayItem->value->class->name, 'Rule')
            && $arrayItem->value->name instanceof Node\Identifier
            && $arrayItem->value->name->name === 'enum'
        ) {
            $args = $arrayItem->value->args;
        }

        if (count($args) !== 1 || ! $args[0] instanceof Node\Arg) {
            return null;
        }

        $arg = $args[0];
        if ($arg->value instanceof Node\Expr\ClassConstFetch
            && $arg->value->class instanceof Node\Name
        ) {
            $className = $arg->value->class->getAttribute('resolvedName');
            // Only prepend '\\' if the class name is already fully qualified (contains '\')
            // For relative names, return as-is and let enum_exists use autoloading to resolve.
            if (mb_strpos($className, '\\') !== false) {
                return '\\'.$className;
            }

            return $className;
        }
        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    protected function getMissingCustomDataMessage($parameterName)
    {
        return "No extra data found for parameter '{$parameterName}' from your inline validator. You can add a comment above '{$parameterName}' with a description and example.";
    }

    protected function shouldCastUserExample()
    {
        return true;
    }

    protected function isValidationStatementMeantForThisStrategy(Node $validationStatement): bool
    {
        return true;
    }

    protected function findValidationExpression($statements): ?array
    {
        $strategies = [
            RequestValidate::class, // $request->validate(...);
            RequestValidateFacade::class, // Request::validate(...);
            ValidatorMake::class, // Validator::make($request, ...)
            ThisValidate::class, // $this->validate(...);
        ];

        foreach ($statements as $index => $node) {
            foreach ($strategies as $strategy) {
                if ($validationRules = $strategy::find($node)) {
                    return [$index, $node, $validationRules];
                }
            }
        }

        return [null, null, null];
    }
}
