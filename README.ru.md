# Antlers PHP

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)

Автономная реализация движка шаблонов [Antlers](https://statamic.dev/frontend/antlers) — альтернативная имплементация для использования **вне экосистемы Statamic/Laravel** в любом PHP 8.2+ проекте.

[English](README.md)

## Установка

```bash
composer require bugo/antlers-php
```

## Быстрый старт

```php
use Bugo\Antlers\Engine;

$engine = new Engine();

echo $engine->render('Привет, {{ name }}!', ['name' => 'мир']);
// → Привет, мир!
```

## Синтаксис

### Переменные

```antlers
{{ name }}
{{ user.profile.name }}
{{ items[0] }}
{{ items[key] }}
```

`items[key]` использует текущую переменную `key` из области видимости как индекс. Для литерального ключа используйте точечную нотацию: `{{ items.key }}`.

### Оператор объединения с null и тернарный оператор

```antlers
{{ name ?? "Гость" }}
{{ logged_in ? "С возвращением" : "Пожалуйста, войдите" }}
```

### Арифметика и строки

```antlers
{{ price * 1.2 }}
{{ count + 1 }}
{{ "Привет" . ", " . name . "!" }}
```

### Условия

```antlers
{{ if score > 90 }}
    Отлично!
{{ elseif score > 70 }}
    Хорошо
{{ else }}
    Нужно постараться
{{ /if }}

{{ unless logged_in }}
    <a href="/login">Войти</a>
{{ /unless }}
```

### Циклы

```antlers
{{# foreach с псевдонимом #}}
{{ foreach items as item }}
    <li>{{ item.title }}</li>
{{ /foreach }}

{{# foreach с ключом и значением #}}
{{ foreach data as key => value }}
    {{ key }}: {{ value }}
{{ /foreach }}

{{# числовой цикл #}}
{{ for 1 to 5 }}
    {{ value }}
{{ /for }}

{{# парный тег — итерирует массив #}}
{{ posts }}
    <h2>{{ title }}</h2>
{{ /posts }}
```

**Переменные внутри цикла включают:**

| Переменная | Описание |
|------------|----------|
| `{{ count }}` | Текущая итерация (начиная с 1) |
| `{{ index }}` | Текущая итерация (начиная с 0) |
| `{{ total }}` | Всего элементов |
| `{{ first }}` | `true` на первой итерации |
| `{{ last }}` | `true` на последней итерации |
| `{{ odd }}` | `true` на нечётных итерациях |
| `{{ even }}` | `true` на чётных итерациях |
| `{{ key }}` | Ключ текущего элемента |

В парных циклах по массиву также доступны соседние значения через двоеточечную нотацию:

```antlers
{{ songs }}
    {{ value }} (next: {{ next:value }}, prev: {{ prev:value }})
{{ /songs }}
```

### Модификаторы

```antlers
{{ title | upper }}
{{ title | lower | truncate:50 }}
{{ price | multiply:1.2 | round:2 }}
{{ items | sort | first }}
{{ date | format:"d.m.Y" }}
```

### Установка переменных

```antlers
{{ set greeting = "Привет" }}
{{ greeting }}, {{ name }}!
```

### Комментарии

```antlers
{{# Этот текст не попадёт в HTML #}}
```

### Noparse

```antlers
{{ noparse }}
    {{ это не будет обработано движком }}
{{ /noparse }}

Одиночный тег: @{{ name }}
```

### Теги

```antlers
{{# самозакрывающийся тег #}}
{{ greeting name="Алиса" }}

{{# тег с методом (пространство имён) #}}
{{ partial:load src="header" }}

{{# парный тег (с содержимым) #}}
{{ wrap tag="section" }}
    Содержимое
{{ /wrap }}
```

## Встроенные модификаторы

Этот проект намеренно поддерживает только официальный поднабор модификаторов Statamic, который хорошо работает в автономном PHP-движке без зависимостей от Laravel/Statamic runtime.

| Статус | Модификаторы |
|--------|--------------|
| Поддерживаемый официальный поднабор | `add`, `ceil`, `chunk`, `contains`, `count`, `decode`, `divide`, `ends_with`, `entities`, `explode`, `first`, `flatten`, `floor`, `format`, `is_array`, `is_empty`, `is_numeric`, `join`, `kebab`, `keys`, `last`, `lcfirst`, `length`, `limit`, `lower`, `markdown`, `md5`, `mod`, `multiply`, `nl2br`, `pad`, `pluck`, `regex_replace`, `repeat`, `replace`, `reverse`, `round`, `sanitize`, `slugify`, `snake`, `sort`, `starts_with`, `strip_tags`, `studly`, `subtract`, `surround`, `title`, `trim`, `truncate`, `ucfirst`, `unique`, `upper`, `values`, `where`, `word_count`, `wrap` |
| Пока не включены | `add_slashes`, `ampersand_list`, `antlers`, `as`, `ascii`, `at`, `attribute`, `background_position`, `bard_html`, `bard_items`, `bard_text`, `bool_string`, `camelize`, `cdata`, `classes`, `collapse`, `collapse_whitespace`, `compact`, `console_log`, `contains_all`, `contains_any`, `count_substring`, `dashify`, `days_ago`, `deslugify`, `dl`, `dump`, `embed_url`, `ensure_left`, `ensure_right`, `excerpt`, `favicon`, `filter_empty`, `flip`, `format_number`, `format_translated`, `full_urls`, `get`, `gravatar`, `group_by`, `has_lower_case`, `has_upper_case`, `headline`, `hex_to_rgb`, `hours_ago`, `image`, `in_array`, `insert`, `is_after`, `is_alpha`, `is_alphanumeric`, `is_before`, `is_between`, `is_blank`, `is_email`, `is_embeddable`, `is_external_url`, `is_future`, `is_json`, `is_leap_year`, `is_lowercase`, `is_numberwang`, `is_past`, `is_today`, `is_tomorrow`, `is_uppercase`, `is_url`, `is_weekday`, `is_weekend`, `is_yesterday`, `iso_format`, `link`, `list`, `macro`, `mailto`, `mark`, `minutes_ago`, `modify_date`, `months_ago`, `obfuscate`, `obfuscate_email`, `offset`, `ol`, `option_list`, `output`, `parse_url`, `partial`, `pathinfo`, `piped`, `plural`, `random`, `raw`, `rawurlencode`, `rawurlencode_except_slashes`, `ray`, `read_time`, `regex_mark`, `relative`, `remove_left`, `remove_right`, `safe_truncate`, `seconds_ago`, `segment`, `select`, `sentence_list`, `shuffle`, `singular`, `smartypants`, `spaceless`, `str_pad_left`, `substr`, `sum`, `swap_case`, `table`, `tidy`, `timezone`, `to_json`, `to_qs`, `to_spaces`, `to_tabs`, `ul`, `underscored`, `url`, `urlencode`, `urlencode_except_slashes`, `where-in`, `weeks_ago`, `widont`, `years_ago` |

<details>
<summary><strong>Строковые</strong></summary>

| Модификатор | Описание | Пример |
|-------------|----------|--------|
| `upper` | Верхний регистр | `{{ name \| upper }}` |
| `lower` | Нижний регистр | `{{ name \| lower }}` |
| `title` | Каждое слово с заглавной буквы | `{{ title \| title }}` |
| `ucfirst` | Первый символ заглавный | `{{ text \| ucfirst }}` |
| `lcfirst` | Первый символ строчный | `{{ text \| lcfirst }}` |
| `slugify` | URL-slug | `{{ title \| slugify }}` |
| `snake` | snake_case | `{{ name \| snake }}` |
| `studly` | StudlyCase | `{{ name \| studly }}` |
| `kebab` | kebab-case | `{{ name \| kebab }}` |
| `trim` | Обрезать пробелы | `{{ text \| trim }}` |
| `truncate` | Обрезать до N символов | `{{ text \| truncate:100:"..." }}` |
| `limit` | Ограничить длину | `{{ text \| limit:50 }}` |
| `word_count` | Подсчитать слова | `{{ text \| word_count }}` |
| `replace` | Замена подстроки | `{{ text \| replace:"old":"new" }}` |
| `regex_replace` | Замена по регулярному выражению | `{{ text \| regex_replace:"/old/":"new" }}` |
| `nl2br` | Переносы → `<br>` | `{{ text \| nl2br }}` |
| `strip_tags` | Удалить HTML-теги | `{{ html \| strip_tags }}` |
| `entities` / `sanitize` | Экранировать HTML | `{{ input \| entities }}` |
| `decode` | Декодировать HTML-сущности | `{{ input \| decode }}` |
| `markdown` | Преобразовать Markdown | `{{ content \| markdown }}` |
| `wrap` | Обернуть в тег | `{{ text \| wrap:"span" }}` |
| `surround` | Добавить текст до/после | `{{ text \| surround:"[":"]" }}` |
| `repeat` | Повторить строку | `{{ text \| repeat:3 }}` |
| `starts_with` | Начинается с | `{{ text \| starts_with:"Привет" }}` |
| `ends_with` | Заканчивается на | `{{ text \| ends_with:"!" }}` |
| `contains` | Содержит подстроку | `{{ text \| contains:"word" }}` |
| `length` | Длина строки | `{{ text \| length }}` |
</details>

<details>
<summary><strong>Числовые</strong></summary>

| Модификатор | Описание | Пример |
|-------------|----------|--------|
| `add` | Прибавить | `{{ price \| add:10 }}` |
| `subtract` | Вычесть | `{{ price \| subtract:5 }}` |
| `multiply` | Умножить | `{{ price \| multiply:1.2 }}` |
| `divide` | Разделить | `{{ total \| divide:100 }}` |
| `mod` | Остаток от деления | `{{ n \| mod:2 }}` |
| `ceil` | Округлить вверх | `{{ value \| ceil }}` |
| `floor` | Округлить вниз | `{{ value \| floor }}` |
| `round` | Округлить | `{{ value \| round:2 }}` |
</details>

<details>
<summary><strong>Массивы</strong></summary>

| Модификатор | Описание | Пример |
|-------------|----------|--------|
| `sort` | Сортировать | `{{ items \| sort:"name" }}` |
| `reverse` | Перевернуть | `{{ items \| reverse }}` |
| `first` | Первый элемент | `{{ items \| first }}` |
| `last` | Последний элемент | `{{ items \| last }}` |
| `pluck` | Извлечь поле | `{{ users \| pluck:"name" }}` |
| `unique` | Уникальные значения | `{{ tags \| unique }}` |
| `where` | Фильтр по полю | `{{ items \| where:"status":"active" }}` |
| `chunk` | Разбить на группы | `{{ items \| chunk:3 }}` |
| `keys` | Ключи массива | `{{ data \| keys }}` |
| `values` | Значения массива | `{{ data \| values }}` |
| `count` | Количество элементов | `{{ items \| count }}` |
| `join` | Объединить в строку | `{{ tags \| join:", " }}` |
| `explode` | Разбить строку | `{{ csv \| explode:"," }}` |
</details>

<details>
<summary><strong>Дата и время</strong></summary>

| Модификатор | Описание | Пример |
|-------------|----------|--------|
| `format` | Форматировать дату | `{{ date \| format:"d.m.Y" }}` |
</details>

<details>
<summary><strong>Утилиты</strong></summary>

| Модификатор | Описание | Пример |
|-------------|----------|--------|
| `is_empty` | Проверить на пустоту | `{{ items \| is_empty }}` |
| `is_array` | Проверить, что значение является массивом | `{{ items \| is_array }}` |
| `is_numeric` | Проверить, что значение числовое | `{{ value \| is_numeric }}` |
| `md5` | MD5-хеш | `{{ email \| md5 }}` |
</details>

## Расширение

### Кастомный модификатор

```php
// Функция
$engine->addModifier('money', function(mixed $value, array $params, array $context): string {
    $currency = $params[0] ?? 'USD';
    return number_format((float) $value, 2) . ' ' . $currency;
});
// {{ price | money:EUR }}

// Класс
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

### Кастомный тег

```php
// Функция
$engine->addTag('icon', function(array $params): string {
    $name = $params['name'] ?? '';
    return "<svg class=\"icon\"><use href=\"#icon-{$name}\"></use></svg>";
});
// {{ icon name="звезда" }}

// Класс с методами (пространство имён)
use Bugo\Antlers\Tags\AbstractTag;

class CacheTag extends AbstractTag
{
    public function index(): string|array|null
    {
        $key = $this->param('key', 'по-умолчанию');
        // ... логика кеша
        return $this->content();
    }

    public function forget(): string|array|null
    {
        $key = $this->param('key', 'по-умолчанию');
        // ... очистка кеша
        return null;
    }
}

$engine->addTag('cache', new CacheTag());
// {{ cache key="главная" }}...{{ /cache }}
// {{ cache:forget key="главная" }}
```

### Парный тег с дочерними узлами

```php
$engine->addTag('repeat', function(array $params, array $data, $processor, $method, $children): string {
    $times  = (int) ($params['times'] ?? 1);
    $output = '';
    for ($i = 0; $i < $times; $i++) {
        $output .= $processor->reduce($children, array_merge($data, ['iteration' => $i + 1]));
    }
    return $output;
});
// {{ repeat times="3" }}{{ iteration }}. Привет!{{ /repeat }}
```

### Глобальные переменные

```php
$engine->setGlobals([
    'site_name' => 'Мой блог',
    'year'      => date('Y'),
    'user'      => $currentUser,
]);

// Доступны во всех шаблонах без передачи в render()
echo $engine->render('© {{ year }} {{ site_name }}');
```

### Строгий режим

```php
$engine->setStrictMode(true);

echo $engine->render('{{ name }}', ['name' => 'Алиса']);
// Алиса

echo $engine->render('{{ missing }}');
// выбросит AntlersRuntimeException
```
