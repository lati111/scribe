# Scribe (RonoIT fork) — Fork Documentation

## Table of Contents

1. [Overview](#1-overview)
2. [Installation](#2-installation)
3. [Docblock Inheritance](#3-docblock-inheritance)
4. [Endpoint Ordering](#4-endpoint-ordering)
5. [Inline Validation Rules](#5-inline-validation-rules)
6. [Validation Rule Descriptions](#6-validation-rule-descriptions)
7. [Testing](#7-testing)
8. [Keeping the Fork in Sync](#8-keeping-the-fork-in-sync)

---

## 1. Overview

This is the RonoIT fork of [knuckleswtf/scribe](https://github.com/knuckleswtf/scribe), kept on [lati111/scribe](https://github.com/lati111/scribe). It generates the API documentation of RonoPortal (`/docs`). It used to live in RonoPortal as patched copies of Scribe 4.38.0 files under `vendor_fixes/`, loaded over the vendor package through `exclude-from-classmap`. The fork carries the same changes on top of Scribe v5, so the portal can depend on it as a normal package.

The fork changes four things:
- `@inheritdoc` in a controller or method docblock pulls in the docblock of the parent.
- Groups, subgroups and endpoints are sorted by order numbers in the endpoint metadata, not by the `groups.order` config.
- Inline validation rules that are not plain strings (`sprintf()`, `Rule::unique()->ignore()`, rule objects) no longer stop a generate run.
- Rule descriptions are worded and joined the way the RonoPortal templates expect, and the `phone` and `unique` rules are documented.

**Not part of the fork:** the RonoPortal Blade templates (`resources/views/vendor/scribe`) and the custom strategies in `app/Docs/Strategies`. The strategies depend on portal models, permissions and the dataprovider package, and the metadata they add is only rendered by the portal templates. They stay in RonoPortal; the strategy that sets the order numbers (`AddOrderTags`) is the one that ties into section 4.

**Key files:**
- `src/Extracting/RouteDocBlocker.php` — docblock inheritance
- `camel/Camel.php` — ordering
- `src/Extracting/Strategies/GetFromInlineValidatorBase.php` — inline rule parsing
- `src/Extracting/ParsesValidationRules.php` — rule descriptions

---

## 2. Installation

Require the fork through a VCS repository in the consuming project's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/lati111/scribe" }
],
"require": {
    "knuckleswtf/scribe": "dev-custom_version"
}
```

When switching RonoPortal over, remove the `Knuckles\\Scribe\\` and `Knuckles\\Camel\\` entries pointing at `vendor_fixes/` from `autoload.psr-4`, the matching `exclude-from-classmap` entries, and the `vendor_fixes/Scribe` and `vendor_fixes/Camel` folders. Keeping both would load the old 4.38.0 copies over the v5 classes they no longer fit.

**v5 is a major upgrade from 4.38.0.** Read the upstream upgrade notes before switching, and run `php artisan scribe:generate` once to check the custom strategies in `app/Docs/Strategies` against the v5 `Strategy` and `ExtractedEndpointData` classes.

---

## 3. Docblock Inheritance

`RouteDocBlocker` replaces an inheritdoc tag in a controller class or method docblock with the docblock of the parent. `@inheritdoc` and `@inheritDoc` are recognised, with or without braces (`{@inheritDoc}`). Abstract API controllers can then hold the `@group`, `@authenticated` and parameter documentation once, while every concrete controller only says `@inheritdoc`.

- **Class docblocks** resolve through every parent: if the parent's docblock is itself `@inheritdoc`, the grandparent's is used for that part, and so on. Tags written next to the inheritdoc tag (for instance a `@subgroup`) are kept.
- **Method docblocks** resolve the same way, starting from the parent of the class that declares the method.
- **Nothing to inherit from:** when there is no parent class, or the parent has no method by that name, the tag is left in place. It does not throw.
- **Closure routes** have no class and are never resolved.

The text is substituted before the docblock is parsed, so the result behaves exactly as if the inherited lines had been written in the child. A child that wants to add to the inherited docblock writes its additions before or after the tag.

The 4.38.0 patch replaced the tag in the child's original docblock on every step, so with two levels of inheritance the middle class's text was lost, and a class without a parent crashed. The fork substitutes into the docblock built so far.

---

## 4. Endpoint Ordering

`Camel::sortByConfigFileOrder()` sorts by three numbers in the custom metadata of the endpoint (`metadata.custom`):

| Key | Sorts | Default |
|---|---|---|
| `group-order` | groups | `999` |
| `subgroup-order` | subgroups within a group | `999` |
| `endpoint-order` | endpoints within a subgroup | `999` |

- **Groups** are sorted by the `group-order` of their *first* endpoint. Give every endpoint of a group the same value; one on a later endpoint is ignored.
- **Endpoints** within a group are sorted by `subgroup-order × 1000 + endpoint-order`. Subgroups therefore stay together, and an endpoint order must stay below 1000.
- **Anything without an order** sorts last (`999`). Groups or endpoints with equal values keep the order Scribe extracted them in.
- **The `groups.order` config is ignored.** The second argument is still accepted so the generate command does not change, but it has no effect. An endpoint without order numbers is not sorted by the config either.

The prepared output groups carry the resolved number as `order`, so templates can read it.

In RonoPortal the numbers come from the `AddOrderTags` metadata strategy, which reads `@group-order` and `@subgroup-order` from the controller docblock and `@endpoint-order` from the method docblock (simplified from RonoPortal's hour APIs):

```php
/**
 * @group Hours
 * @group-order 4
 */
abstract class AbstractHourApi extends AbstractApi { ... }

/**
 * @inheritdoc
 * @subgroup Hour registration
 * @subgroup-order 1
 */
class HourApi extends AbstractHourApi
{
    /**
     * Get all hours.
     *
     * @endpoint-order 1
     */
    public function getHours(Request $request) { ... }
}
```

The order tags are read after inheritance is resolved (section 3), so the abstract API sets `@group-order` once for every API that inherits its docblock. Each concrete API sets its own `@subgroup-order`.

---

## 5. Inline Validation Rules

Scribe reads inline validation (`$request->validate([...])`, `Validator::make(...)`, `$this->validate(...)`) from the source code, not by running it, so only plain strings can be turned into documentation. Before the rules are read, `GetFromInlineValidatorBase::parseArrayRules()` rewrites the rules in an array that cannot be read that way:

| Rule in the source | Becomes |
|---|---|
| `sprintf('in:%s,%s', Status::OPEN, Status::CLOSED)` | the formatted string, here `in:open,closed` |
| `Rule::unique(...)->ignore(...)` (any chain on `Rule::unique`) | `unique` |
| `new SomeRule(...)` | `class:SomeRule` |
| any other function call, e.g. `trim(...)` | `call:trim` |
| `new Enum(...)`, `Rule::enum(...)` | unchanged, still read as an enum |

- **`sprintf()`** is evaluated when every argument is a string or a class constant. A constant is looked up through the imported class name, or failing that any loaded class whose name ends in it. If any argument cannot be resolved (an unknown constant, a variable, a method call) the whole call becomes `call:sprintf` and is skipped. `self::` and `static::` cannot be resolved from the source; reference the class by name.
- **Placeholders** (`class:`, `call:`, `method:`) are never real Laravel rule names, so the description builder skips them. The parameter is still documented from the rules next to it.
- **Rule objects** carry their logic in PHP, so there is nothing to describe from the source. Describe the parameter in the comment above it instead.

Before these rewrites, such rules stopped the run outright: an unknown rule object hit a `dd()`, and the first endpoint with a different rule object (`DialablePhoneNumber` on `POST api/calls/start`) killed the whole generate run.

---

## 6. Validation Rule Descriptions

`ParsesValidationRules` builds a parameter description from the description in the comment or `bodyParameters()`, followed by one sentence per rule. The fork changes how those sentences are written and joined:

- **Joined with `<br>`.** Each rule's sentence goes on its own line instead of being glued on with a space. A description never starts with `<br>`.
- **No forced full stop.** Upstream ends every description with a full stop. The fork only adds one to the description from the comment; rule sentences keep their own punctuation, so some (`not_in`, `starts_with`, `ends_with`) end without one.
- **English translations.** Descriptions come from Laravel's `validation.*` messages, always in `en`, whatever the application locale is. RonoPortal runs in Dutch, and the documentation is English.
- **The parameter name** replaces `:attribute` in the sentences written by the fork itself (`url`, `not_in`, `starts_with`, `ends_with`), e.g. "The url_param must be a valid URL."

Rule-specific behaviour:

| Rule | Description | Note |
|---|---|---|
| `phone` | Must be a valid phone number. | Not a Laravel rule; registered by RonoPortal. Example is a Dutch mobile number (`06` + 8 digits). |
| `unique` | Must be unique. | |
| `exists:table,column` | The `column` of an existing record in the table table. | Without a column, the parameter name is used, as Laravel does. |
| `file` | *(none)* | Only sets the type. |
| `required_without` | The value is required when … is not present. | Laravel's message would be turned into "must be present", inverting its meaning. |
| `required_if` / `required_unless` | The value is required when / unless … | |
| `*_all` variants | … `a` and `b` … | Other variants join the field names with "or". |

**Date rules referring to another field** (`before:start_date`) are documented with the field name, and the example is generated relative to today. In the 4.38.0 patch the field name was passed to Faker as a date and the run failed.

The v5 additions are kept: `sometimes` stops `required` and `accepted` from marking a parameter required, and numeric values in `in:` are cast to numbers in `enumValues`.

---

## 7. Testing

The suite runs with Pest; see `AGENTS.md` for the commands. The fork's behaviour is covered by:

- `tests/Unit/RouteDocBlockerTest.php` — inheritdoc through one and two levels, class tags kept, missing parent method. Fixtures in `tests/Fixtures/TestInheritDocController.php`, which is excluded from Pint because Pint rewrites inheritdoc tags.
- `tests/Unit/CamelCustomOrderTest.php` — group, subgroup and endpoint order, defaults, and the ignored config order. It replaces the upstream `OutputTest` cases for `groups.order`.
- `tests/Strategies/GetFromInlineValidatorTest.php` (`test_can_fetch_rules_which_are_not_plain_strings`) — `sprintf()`, `Rule::unique()->ignore()`, rule objects and other function calls, against `TestController::withRulesNotReadableFromAst`.
- `tests/Unit/ValidationRuleParsingTest.php` — the wording of every supported rule, `<br>` joining, `phone`, `unique` and `exists` without a column.

`BehavioursTest::test_calls_after_generating_hook_with_correct_paths` fails on Windows because it builds the expected paths with the wrong directory separator. That is an upstream test issue, not a fork change.

On Windows, run Pint on LF line endings (as committed): the working copy is CRLF, and Pint then reports layout fixers on every file.

---

## 8. Keeping the Fork in Sync

`custom_version` is upstream `v5` plus the fork commits. To take a new upstream release, merge `origin/v5` into `custom_version` and resolve conflicts in the four files from section 1. Upstream tests that assert rule wording or config ordering will conflict or fail; the fork's wording and ordering win, so update those expectations rather than the code.

When upstream adds a validation rule to `processRule()`, port it into the matching `format*Rules()` method and use `formatRuleDescription()` for its sentence so it is joined like the others.
