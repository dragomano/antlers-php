# Antlers PHP

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)

A standalone implementation of the [Antlers](https://statamic.dev/frontend/antlers) templating engine, designed for use outside the Statamic/Laravel ecosystem in any PHP 8.2+ project.

[По-русски](README.ru.md)

## Positioning

`antlers-php` targets a standalone Antlers subset for regular PHP projects.

This means the project aims to support the core Antlers language that works predictably without Statamic or Laravel, such as variables, expressions, conditions, loops, modifiers, partials, and custom tags/modifiers.

It does not promise full compatibility with every Statamic tag, modifier, CMS feature, or Laravel-dependent integration.

In particular, `{{? ?}}` and `{{$ $}}` PHP delimiters from Statamic Antlers are intentionally not supported in `antlers-php`. This project keeps PHP execution out of the standalone core instead of exposing it as a template feature.

## Installation

```bash
composer require bugo/antlers-php
```

## Quick Start

```php
use Bugo\Antlers\Engine;

$engine = new Engine();

echo $engine->render('Hello, {{ name }}!', ['name' => 'World']);
// → Hello, World!
```

## Security

`antlers-php` does not auto-escape `{{ ... }}` output by default. This is intentional for standalone Antlers compatibility.

When rendering user-provided content into HTML, explicitly escape it with `sanitize` or `entities`:

```antlers
{{ comment | sanitize }}
{{ email | entities }}
```

Treat plain `{{ ... }}` output as raw template output unless you have applied the escaping yourself.

## Syntax

### Variables

```antlers
{{ name }}
{{ user.profile.name }}
{{ items[0] }}
{{ items[key] }}
```

`items[key]` uses the current scope variable `key` as the index. For a literal key, use dot notation: `{{ items.key }}`.

### Null Coalescing and Ternary Operator

```antlers
{{ name ?? "Guest" }}
{{ logged_in ? "Welcome back" : "Please log in" }}
```

### Arithmetic and Strings

```antlers
{{ price * 1.2 }}
{{ count + 1 }}
{{ "Hello" . ", " . name . "!" }}
```

### Conditions

```antlers
{{ if score > 90 }}
    Excellent!
{{ elseif score > 70 }}
    Good
{{ else }}
    Needs improvement
{{ /if }}

{{ unless logged_in }}
    <a href="/login">Log in</a>
{{ /unless }}
```

### Loops

```antlers
{{# foreach with alias #}}
{{ foreach items as item }}
    <li>{{ item.title }}</li>
{{ /foreach }}

{{# foreach with key and value #}}
{{ foreach data as key => value }}
    {{ key }}: {{ value }}
{{ /foreach }}

{{# statamic-compatible foreach forms #}}
{{ foreach:company_info }}
    {{ key }}: {{ value }}
{{ /foreach:company_info }}

{{ foreach:song_reviews as="song|rating" }}
    {{ song }}: {{ rating }}
{{ /foreach:song_reviews }}

{{ foreach :array="reviews:songs" as="song|rating" }}
    {{ song }}: {{ rating }}
{{ /foreach }}

{{# numeric loop #}}
{{ for 1 to 5 }}
    {{ value }}
{{ /for }}

{{# paired tag that iterates over an array #}}
{{ posts }}
    <h2>{{ title }}</h2>
{{ /posts }}
```

**Variables available inside loops include:**

| Variable | Description |
|----------|-------------|
| `{{ count }}` | Current iteration number (starting from 1) |
| `{{ index }}` | Current iteration number (starting from 0) |
| `{{ total }}` | Total number of items |
| `{{ first }}` | `true` on the first iteration |
| `{{ last }}` | `true` on the last iteration |
| `{{ odd }}` | `true` on odd iterations |
| `{{ even }}` | `true` on even iterations |
| `{{ key }}` | Key of the current item |

Paired array loops also support neighbor access via colon notation:

```antlers
{{ songs }}
    {{ value }} (next: {{ next:value }}, prev: {{ prev:value }})
{{ /songs }}
```

### Modifiers

```antlers
{{ title | upper }}
{{ title | lower | truncate:50 }}
{{ price | multiply:1.2 | round:2 }}
{{ items | sort | first }}
{{ date | format:"d.m.Y" }}
```

### Setting Variables

```antlers
{{ set greeting = "Hello" }}
{{ greeting }}, {{ name }}!
```

### Comments

```antlers
{{# This text will not appear in the HTML output #}}
```

`{{? ?}}` and `{{$ $}}` are not available in this engine. If you need PHP, keep it outside Antlers templates in your application code.

### Noparse

```antlers
{{ noparse }}
    {{ this will not be processed by the engine }}
{{ /noparse }}

Single tag: @{{ name }}
```

### Tags

```antlers
{{# self-closing tag #}}
{{ greeting name="Alice" }}

{{# tag with method (namespace) #}}
{{ partial:header title="Welcome" }}

{{# paired tag (with content) #}}
{{ markdown }}
**Bold**
{{ /markdown }}
```

## Built-in Tags

The standalone core currently registers these built-in tags:

`dump`, `foreach`, `increment`, `layout`, `loop`, `markdown`, `once`, `partial`, `prepend`, `push`, `scope`, `section`, `slot`, `stack`, `svg`, `switch`, `yield`

`set` is also supported as Antlers syntax for variable assignment, but it is language syntax rather than a registered tag from `CoreTags`.

### Core Tag Examples

```antlers
{{ partial src="partials/card.antlers.html" title="Hello" }}
{{ partial:header title="Welcome" }}
{{ partial:exists src="partials/card.antlers.html" }}
{{ partial:if_exists src="partials/maybe.antlers.html" }}

{{ section:hero }}<h1>{{ title }}</h1>{{ /section:hero }}
{{ yield:hero }}
{{ yield:sidebar }}Fallback sidebar{{ /yield:sidebar }}

{{ markdown }}**Bold**{{ /markdown }}
{{ markdown:indent }}
    # Title
{{ /markdown:indent }}

{{ loop times="3" }}{{ value }}{{ /loop }}
{{ loop count="3" start="5" }}{{ value }}{{ /loop }}
{{ loop:2 }}{{ value }}{{ /loop:2 }}

{{ switch between="odd|even" }}
{{ switch name="rows" in="a|b" }}

{{ scope:page }}{{ page:title }}{{ /scope:page }}
{{ dump value=user }}
{{ svg src="icons/logo.svg" }}
{{ increment }}
{{ increment:row from="10" by="5" }}
```

## Built-in Modifiers

This project intentionally supports an official subset of Statamic modifiers that works well in a standalone PHP engine, without Laravel/Statamic runtime dependencies.

| Status | Modifiers |
|--------|-----------|
| Supported official subset | `add`, `ceil`, `chunk`, `contains`, `count`, `decode`, `divide`, `ends_with`, `entities`, `explode`, `first`, `flatten`, `floor`, `format`, `is_array`, `is_empty`, `is_numeric`, `join`, `kebab`, `keys`, `last`, `lcfirst`, `length`, `limit`, `lower`, `markdown`, `md5`, `mod`, `multiply`, `nl2br`, `pad`, `pluck`, `regex_replace`, `repeat`, `replace`, `reverse`, `round`, `sanitize`, `slugify`, `snake`, `sort`, `starts_with`, `strip_tags`, `studly`, `subtract`, `surround`, `title`, `trim`, `truncate`, `ucfirst`, `unique`, `upper`, `values`, `where`, `word_count`, `wrap` |

### Disputed Statamic Modifiers

`antlers`, `partial`, and `raw` are intentionally excluded from the standalone modifier API.

- `partial` stays a tag concern in this project: use `partial`, `partial:exists`, and `partial:if_exists` instead of a modifier.
- `antlers` is not part of the first stable standalone core because re-rendering strings as templates needs a separate execution model and explicit recursion safeguards.
- `raw` is not included because `antlers-php` does not auto-escape output by default; literal/raw behavior is already covered by normal output, `@{{ ... }}`, and `noparse`.

<details>
<summary><strong>String</strong></summary>

| Modifier | Description | Example |
|----------|-------------|---------|
| `upper` | Uppercase | `{{ name \| upper }}` |
| `lower` | Lowercase | `{{ name \| lower }}` |
| `title` | Title Case | `{{ title \| title }}` |
| `ucfirst` | Capitalize the first character | `{{ text \| ucfirst }}` |
| `lcfirst` | Lowercase the first character | `{{ text \| lcfirst }}` |
| `slugify` | URL slug | `{{ title \| slugify }}` |
| `snake` | snake_case | `{{ name \| snake }}` |
| `studly` | StudlyCase | `{{ name \| studly }}` |
| `kebab` | kebab-case | `{{ name \| kebab }}` |
| `trim` | Trim whitespace | `{{ text \| trim }}` |
| `truncate` | Truncate to N characters | `{{ text \| truncate:100:"..." }}` |
| `limit` | Limit length | `{{ text \| limit:50 }}` |
| `word_count` | Count words | `{{ text \| word_count }}` |
| `replace` | Replace substring | `{{ text \| replace:"old":"new" }}` |
| `regex_replace` | Replace using a regex pattern | `{{ text \| regex_replace:"/old/":"new" }}` |
| `nl2br` | Line breaks → `<br>` | `{{ text \| nl2br }}` |
| `strip_tags` | Remove HTML tags | `{{ html \| strip_tags }}` |
| `entities` / `sanitize` | Escape HTML | `{{ input \| entities }}` |
| `decode` | Decode HTML entities | `{{ input \| decode }}` |
| `markdown` | Parse Markdown | `{{ content \| markdown }}` |
| `wrap` | Wrap in an HTML tag | `{{ text \| wrap:"span" }}` |
| `surround` | Add text before/after | `{{ text \| surround:"[":"]" }}` |
| `repeat` | Repeat string | `{{ text \| repeat:3 }}` |
| `starts_with` | Starts with | `{{ text \| starts_with:"Hello" }}` |
| `ends_with` | Ends with | `{{ text \| ends_with:"!" }}` |
| `contains` | Contains substring | `{{ text \| contains:"word" }}` |
| `length` | String length | `{{ text \| length }}` |
</details>

<details>
<summary><strong>Numeric</strong></summary>

| Modifier | Description | Example |
|----------|-------------|---------|
| `add` | Add | `{{ price \| add:10 }}` |
| `subtract` | Subtract | `{{ price \| subtract:5 }}` |
| `multiply` | Multiply | `{{ price \| multiply:1.2 }}` |
| `divide` | Divide | `{{ total \| divide:100 }}` |
| `mod` | Modulo | `{{ n \| mod:2 }}` |
| `ceil` | Round up | `{{ value \| ceil }}` |
| `floor` | Round down | `{{ value \| floor }}` |
| `round` | Round | `{{ value \| round:2 }}` |
</details>

<details>
<summary><strong>Array</strong></summary>

| Modifier | Description | Example |
|----------|-------------|---------|
| `sort` | Sort | `{{ items \| sort:"name" }}` |
| `reverse` | Reverse | `{{ items \| reverse }}` |
| `first` | First element | `{{ items \| first }}` |
| `last` | Last element | `{{ items \| last }}` |
| `pluck` | Extract a field | `{{ users \| pluck:"name" }}` |
| `unique` | Unique values | `{{ tags \| unique }}` |
| `where` | Filter by field | `{{ items \| where:"status":"active" }}` |
| `chunk` | Split into groups | `{{ items \| chunk:3 }}` |
| `keys` | Array keys | `{{ data \| keys }}` |
| `values` | Array values | `{{ data \| values }}` |
| `count` | Number of items | `{{ items \| count }}` |
| `join` | Join into a string | `{{ tags \| join:", " }}` |
| `explode` | Split a string | `{{ csv \| explode:"," }}` |
</details>

<details>
<summary><strong>Date and Time</strong></summary>

| Modifier | Description | Example |
|----------|-------------|---------|
| `format` | Format a date | `{{ date \| format:"d.m.Y" }}` |

Current standalone strategy:

- The built-in date/time surface is intentionally minimal and currently limited to `format`.
- `format` accepts Unix timestamps and strings that PHP can parse via `strtotime()`.
- If parsing fails, the original string is returned unchanged.
- Carbon is intentionally not a dependency of this project.
- Carbon-style or locale-aware modifiers such as `iso_format`, `modify_date`, `days_ago`, `is_today`, or `timezone` are not part of the first stable standalone core.
- If richer date/time support is added later, it should be built on native PHP types such as `DateTimeImmutable`, `DateTimeInterface`, and `DateTimeZone`, preferably as an opt-in extension.
</details>

<details>
<summary><strong>Utilities</strong></summary>

| Modifier | Description | Example |
|----------|-------------|---------|
| `is_empty` | Check if empty | `{{ items \| is_empty }}` |
| `is_array` | Check if value is an array | `{{ items \| is_array }}` |
| `is_numeric` | Check if value is numeric | `{{ value \| is_numeric }}` |
| `md5` | MD5 hash | `{{ email \| md5 }}` |
</details>

## Extending

### Custom Modifier

```php
// Callable
$engine->addModifier('money', function(mixed $value, array $params, array $context): string {
    $currency = $params[0] ?? 'USD';
    return number_format((float) $value, 2) . ' ' . $currency;
});
// {{ price | money:EUR }}

// Class
use Bugo\Antlers\Modifiers\ModifierInterface;

class ExcerptModifier implements ModifierInterface
{
    public function modify(mixed $value, array $params, array $context): mixed
    {
        $length = (int) ($params[0] ?? 150);
        return mb_substr(strip_tags((string) $value), 0, $length) . '...';
    }
}

$engine->addModifier('excerpt', new ExcerptModifier());
// {{ content | excerpt:200 }}
```

### Custom Tag

Built-in `cache`/`nocache` are not part of the required standalone core right now. If you need cache-like behavior, you can add it as a custom extension:

```php
// Callable
$engine->addTag('icon', function(array $params): string {
    $name = $params['name'] ?? '';
    return "<svg class=\"icon\"><use href=\"#icon-{$name}\"></use></svg>";
});
// {{ icon name="star" }}

// Class with methods (namespace)
use Bugo\Antlers\Tags\AbstractTag;

class CacheTag extends AbstractTag
{
    public function index(): string|array|null
    {
        $key = $this->param('key', 'default');
        // ... cache logic
        return $this->content();
    }

    public function forget(): string|array|null
    {
        $key = $this->param('key', 'default');
        // ... clear cache
        return null;
    }
}

$engine->addTag('cache', new CacheTag());
// {{ cache key="homepage" }}...{{ /cache }}
// {{ cache:forget key="homepage" }}
```

### Paired Tag with Children

```php
$engine->addTag('repeat', function(array $params, array $data, $processor, $method, $children): string {
    $times  = (int) ($params['times'] ?? 1);
    $output = '';
    for ($i = 0; $i < $times; $i++) {
        $output .= $processor->reduce($children, array_merge($data, ['iteration' => $i + 1]));
    }
    return $output;
});
// {{ repeat times="3" }}{{ iteration }}. Hello!{{ /repeat }}
```

### Global Variables

```php
$engine->setGlobals([
    'site_name' => 'My Blog',
    'year'      => date('Y'),
    'user'      => $currentUser,
]);

// Available in all templates without passing them to render()
echo $engine->render('© {{ year }} {{ site_name }}');
```

### Strict Mode

```php
$engine->setStrictMode(true);

echo $engine->render('{{ name }}', ['name' => 'Alice']);
// Alice

echo $engine->render('{{ missing }}');
// throws AntlersRuntimeException
```
