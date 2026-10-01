# Antlers PHP

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/antlers-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/antlers-php?branch=main)

Автономная реализация движка шаблонов [Antlers](https://statamic.dev/frontend/antlers) — для использования **вне экосистемы Statamic/Laravel** в любом PHP 8.2+ проекте.

[English](README.md)

## Позиционирование

`antlers-php` нацелен на автономное подмножество Antlers для обычных PHP-проектов.

Это значит, что проект стремится поддерживать базовое ядро языка Antlers, которое предсказуемо работает без Statamic и Laravel: переменные, выражения, условия, циклы, модификаторы, partials и пользовательские расширения через теги и модификаторы.

Проект не обещает полную совместимость со всеми тегами Statamic, всеми модификаторами, CMS-возможностями или Laravel-зависимыми интеграциями.

В частности, PHP-делимитеры `{{? ?}}` и `{{$ $}}` из Statamic Antlers намеренно не поддерживаются в `antlers-php`. Этот проект сознательно не включает выполнение PHP в standalone-ядро шаблонизатора.

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

## Безопасность

`antlers-php` по умолчанию не применяет автоэкранирование к выводу `{{ ... }}`. Это сделано намеренно для совместимости с автономным Antlers.

Когда в HTML попадает пользовательский ввод, экранируйте его явно через `sanitize` или `entities`:

```antlers
{{ comment | sanitize }}
{{ email | entities }}
```

Считайте обычный вывод `{{ ... }}` сырым выводом шаблона, если вы не применили экранирование самостоятельно.

Исключение — тег и модификатор `markdown`: они предназначены для обработки недоверенного текста,
поэтому сырой HTML в исходнике экранируется, а небезопасные схемы ссылок отбрасываются.
См. [Рендерер Markdown](#рендерер-markdown).

## Синтаксис

### Переменные

```antlers
{{ name }}
{{ user.profile.name }}
{{ items[0] }}
{{ items[key] }}
{{ items['name'] }}
{{ matrix[1][0] }}
```

`items[key]` использует текущую переменную `key` из области видимости как индекс, а `items['name']` и
`items.name` читают литеральный ключ. Индекс может быть путём (`items[a.b]`), а индексы —
цепочкой (`matrix[1][0]`), но индекс — не полноценное выражение: `items[i + 1]` даёт
синтаксическую ошибку, а не молчаливый промах.

Значение выводится одинаково везде — напрямую, через модификатор или через тег. Логические
значения читаются как `true` и `false`, массив склеивает свои части, `null` и объект без
`__toString()` не выводят ничего:

```antlers
{{ flag }} {{ flag | upper }}      {{# true TRUE #}}
{{ parts }} {{ parts | upper }}    {{# xy XY, для ['x', 'y'] #}}
```

### Операторы объединения и тернарный оператор

```antlers
{{ name ?? "Гость" }}
{{ power_level ??? "Больше 9000!" }}
{{ logged_in ? "С возвращением" : "Пожалуйста, войдите" }}
```

`??` подставляет значение справа, когда левая часть *ложна* по тем же правилам, что и `{{ if }}`:
`null`, `false`, `''`, `'0'`, `0`, `0.0` и пустой массив. Так же ведёт себя Statamic, где `??`
задокументирован как возврат первого значения, прошедшего проверку на истинность.

`???` подставляет значение только на `null`, так что `0`, `false` и `''` сохраняются:

```antlers
{{ v ?? "замена" }}   {{# v = 0 → замена #}}
{{ v ??? "замена" }}  {{# v = 0 → 0 #}}
```

Ни один из операторов не бросает исключение на неопределённую левую часть даже в строгом режиме —
оба являются явным «возьми, если есть».

### Выражение switch

`switch(...)` сопоставляет условия в скобках со значениями и возвращает первое совпадение; пара с
пустыми скобками — запасное значение, когда ничего не совпало. Условия и значения — полноценные
выражения, так что модификаторы, тернарники и арифметика работают внутри:

```antlers
{{ switch(
    (size == 'sm') => '35vw',
    (size == 'lg') => '75vw',
    () => '100vw'
) }}
```

Без пары `()` переключатель без совпадений рендерит пустоту. Это выражение-первичное, поэтому оно
компонуется везде, где работает выражение — `{{ x = switch(...) }}`, `{{ switch(...) | upper }}`,
`{{ if switch(...) == 'big' }}`, а также внутри параметров тегов, где теговые пары недопустимы.
Не путать с циклическим тегом `{{ switch between="a|b" }}`, который при каждом появлении отдаёт
следующее значение.

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

{{# statamic-совместимые формы foreach #}}
{{ foreach:company_info }}
    {{ key }}: {{ value }}
{{ /foreach:company_info }}

{{ foreach:song_reviews as="song|rating" }}
    {{ song }}: {{ rating }}
{{ /foreach:song_reviews }}

{{ foreach :array="reviews:songs" as="song|rating" }}
    {{ song }}: {{ rating }}
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

Парный блок выбирает режим по ключам значения: целочисленные ключи итерируются — включая дыры после
`array_filter()` или `unset()` и массивы с нумерацией с единицы, — а любой строковый ключ означает
один элемент, чьи поля становятся переменными.

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

### Группировка коллекций

`groupby` превращает коллекцию в список frame'ов групп, по одному на каждое уникальное значение
сгруппированных полей:

- `key` — значение группы; массив, когда полей группировки несколько;
- `group` — отображаемая метка, здесь совпадающая с ключом: отдельного источника меток у движка нет;
- `items` — элементы группы.

Каждое поле группировки копируется во frame под своим именем или под псевдонимом в скобках, поэтому
`groupby (team 'club')` добавляет `club`; `as '…'` переименовывает элементы и сохраняет `items` рядом.

```antlers
{{ res = items groupby (role) }}{{ res }}{{ group }}:{{ items }}{{ name }},{{ /items }};{{ /res }}
{{# → admin:Alice,Cara,;editor:Bob,; #}}

{{ res = items groupby (role) }}{{ res }}{{ key }}/{{ group }};{{ /res }}
{{# → 0/admin;1/editor; #}}
```

Читайте значение группы через `{{ group }}`: `{{ key }}` внутри такого цикла — ключ самого цикла,
потому что метаданные цикла приоритетнее поля элемента.

### Модификаторы

```antlers
{{ title | upper }}
{{ title | lower | truncate:50 }}
{{ price | multiply:1.2 | round:2 }}
{{ items | sort | first }}
{{ date | format:"d.m.Y" }}
{{ rows | pluck:title }}
{{ title | truncate:$limit }}
```

Параметр после `:` — литерал, а не обращение к переменной: `{{ rows | pluck:title }}` передаёт
строку `title`, поэтому форма, которую пишете вы, и есть форма, которая отработает. Значение
передаётся через `$` (`| truncate:$limit`, также `$user.name` и `$sizes[0]`), всё, что не является
одним словом, берётся в кавычки (`| format:"Y-m-d"`), либо используются скобки — полноценное
выражение, где голое слово *является* переменной (`{{ text | replace("worst", $new) }}`).

### Установка переменных

```antlers
{{ set greeting = "Привет" }}
{{ greeting }}, {{ name }}!
```

`{{ greeting = "Привет" }}` — то же присваивание в виде выражения.

Присваивание пишет в самый внутренний frame области видимости. Frame открывают сам рендер,
каждая итерация цикла, а также тело каждого partial и секции — условия и блоки по истинному
значению frame не создают. Поэтому значение, заданное внутри `{{ if }}`, остаётся доступным и после
блока, а заданное в теле цикла исчезает вместе с итерацией:

```antlers
{{ if user }}{{ set label = "участник" }}{{ /if }}{{ label }}   {{# участник #}}
{{ items }}{{ set seen = title }}{{ /items }}{{ seen }}         {{# пусто #}}
```

### Комментарии

```antlers
{{# Этот текст не попадёт в HTML #}}
```

`{{? ?}}` и `{{$ $}}` в этом движке недоступны. Если нужен PHP, размещайте его в коде приложения, а не внутри Antlers-шаблонов.

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
{{ partial:header title="Добро пожаловать" }}

{{# парный тег (с содержимым) #}}
{{ markdown }}
**Жирный**
{{ /markdown }}
```

Зарегистрированное имя — вызов тега в любой форме записи: с методом, с параметрами и парным
блоком без параметров. Имена разрешаются по реестру тегов, так что тег, добавленный через
`addTag()`, ведёт себя точно так же, как встроенный. Одинокое слово после имени — это флаг-параметр,
приходящий как `true`:

```antlers
{{ box }}...{{ /box }}   {{# парный вызов без параметров #}}
{{ box flag }}           {{# параметр "flag" равен true #}}
```

Зарегистрированный тег выигрывает у одноимённой переменной. Префикс `$` принудительно
выбирает переменную, префикс `%` — тег:

```antlers
{{ $box }}{{ value }}{{ /$box }}   {{# переменная, даже если есть тег box #}}
{{ %box }}                        {{# тег, даже если есть переменная box #}}
```

Незарегистрированное имя, записанное как тег, так и сообщается — сообщение называет тег, а не
жалуется на его параметры:

```
{{ cache key="home" }}   {{# Unknown tag: "cache" #}}
```

Чем является `{{ name }}` — подстановкой или парным блоком — решается для каждого вхождения
отдельно, по наличию парного `{{ /name }}`. Поэтому одно и то же имя можно использовать
в одном шаблоне и так, и так:

```antlers
Всего: {{ items | length }}
{{ items }}<li>{{ value }}</li>{{ /items }}
```

Закрывающий тег обязан закрывать самый внутренний открытый блок, и каждый блок должен быть
закрыт. Перекрещенные, несовпадающие, незакрытые и сиротские закрывающие теги выбрасывают
`AntlersSyntaxException` с указанием строки, а не рендерятся как получится:

```antlers
{{ if true }}A{{ /foreach }}   {{# Unexpected closing tag {{ /foreach }}, expected {{ /if }} #}}
{{ if true }}A                {{# Unclosed tag {{ if }} #}}
{{ /if }}                     {{# Unexpected closing tag {{ /if }} #}}
```

Каждый `AntlersSyntaxException` несёт `templateLine` и `templateSource` и повторяет их в тексте
сообщения, так что одного `getMessage()` достаточно, чтобы найти место ошибки:

```
Expected ")" but found end of expression on line 5 in "( 1 + 2"
```

## Встроенные теги

В standalone-ядре сейчас зарегистрированы такие встроенные теги:

`dump`, `foreach`, `increment`, `layout`, `loop`, `markdown`, `once`, `partial`, `prepend`, `push`, `scope`, `section`, `slot`, `stack`, `svg`, `switch`, `yield`

`set` тоже поддерживается, но это синтаксис Antlers для присваивания переменных, а не зарегистрированный тег из `CoreTags`.

### Примеры встроенных тегов

```antlers
{{ partial src="partials/card.antlers.html" title="Привет" }}
{{ partial:header title="Добро пожаловать" }}
{{ partial:exists src="partials/card.antlers.html" }}
{{ partial:if_exists src="partials/maybe.antlers.html" }}

{{ section:hero }}<h1>{{ title }}</h1>{{ /section:hero }}
{{ yield:hero }}
{{ yield:sidebar }}Запасной сайдбар{{ /yield:sidebar }}

{{ markdown }}**Жирный**{{ /markdown }}
{{ markdown:indent }}
    # Заголовок
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

## Встроенные модификаторы

Этот проект намеренно поддерживает только официальный поднабор модификаторов Statamic, который хорошо работает в автономном PHP-движке без зависимостей от среды выполнения Laravel/Statamic.

Поддерживаемый официальный поднабор:

- `add`
- `ascii`
- `camelize`
- `ceil`
- `chunk`
- `compact`
- `contains`
- `contains_all`
- `contains_any`
- `count`
- `dashify`
- `decode`
- `deslugify`
- `divide`
- `dump`
- `ends_with`
- `ensure_left`
- `ensure_right`
- `entities`
- `excerpt`
- `explode`
- `filter_empty`
- `first`
- `flatten`
- `floor`
- `format`
- `headline`
- `is_array`
- `is_empty`
- `is_numeric`
- `join`
- `kebab`
- `keys`
- `last`
- `lcfirst`
- `length`
- `limit`
- `lower`
- `markdown`
- `md5`
- `mod`
- `multiply`
- `nl2br`
- `offset`
- `pad`
- `parse_url`
- `pathinfo`
- `pluck`
- `random`
- `rawurlencode`
- `regex_replace`
- `remove_left`
- `remove_right`
- `repeat`
- `replace`
- `reverse`
- `round`
- `sanitize`
- `shuffle`
- `slugify`
- `snake`
- `sort`
- `starts_with`
- `strip_tags`
- `studly`
- `substr`
- `subtract`
- `sum`
- `surround`
- `title`
- `to_json`
- `to_qs`
- `trim`
- `truncate`
- `type_of`
- `ucfirst`
- `unique`
- `upper`
- `urldecode`
- `urlencode`
- `values`
- `where`
- `word_count`
- `wrap`

### Спорные модификаторы Statamic

`antlers`, `partial` и `raw` намеренно не входят в автономный API модификаторов.

- `partial` в этом проекте остается задачей тегов: вместо модификатора используются `partial`, `partial:exists` и `partial:if_exists`.
- `antlers` не входит в первое стабильное автономное ядро, потому что повторный рендер строк как шаблонов требует отдельной модели выполнения и явной защиты от рекурсии.
- `raw` не включается, потому что `antlers-php` по умолчанию не делает автоэкранирование; literal/raw-семантика уже покрывается обычным выводом, `@{{ ... }}` и `noparse`.

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
| `contains` | Содержит подстроку | `{{ text \| contains:"слово" }}` |
| `contains_all` | Содержит все иглы (без учета регистра) | `{{ text \| contains_all:"один":"два" }}` |
| `contains_any` | Содержит любую из игл | `{{ text \| contains_any:"один":"два" }}` |
| `ensure_left` | Добавить префикс, если его нет | `{{ url \| ensure_left:"www." }}` |
| `ensure_right` | Добавить суффикс, если его нет | `{{ url \| ensure_right:"/" }}` |
| `remove_left` | Удалить префикс, если он есть | `{{ url \| remove_left:"www." }}` |
| `remove_right` | Удалить суффикс, если он есть | `{{ file \| remove_right:".php" }}` |
| `substr` | Мультибайтовая подстрока | `{{ text \| substr:0:3 }}` |
| `ascii` | Транслитерация в ASCII | `{{ text \| ascii }}` |
| `camelize` | camelCase | `{{ text \| camelize }}` |
| `dashify` | Строчные через дефис | `{{ text \| dashify }}` |
| `deslugify` | Дефисы/подчеркивания → пробелы | `{{ slug \| deslugify }}` |
| `headline` | Заголовочный регистр с правилами для малых слов (есть параметр `mla`) | `{{ title \| headline }}` |
| `excerpt` | Оборвать контент по маркеру (`<!--more-->` по умолчанию) | `{{ content \| excerpt }}` |
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
| `sum` | Сумма значений, опционально по ключу | `{{ items \| sum:"price" }}` |
| `filter_empty` | Убрать ложные значения, ключи сохраняются | `{{ items \| filter_empty }}` |
| `compact` | Список имен переменных через запятую → массив | `{{ list \| compact }}` |
| `offset` | Срез со смещения, с перенумерацией | `{{ items \| offset:2 }}` |
| `shuffle` | Случайный порядок (массивы и строки) | `{{ items \| shuffle }}` |
| `random` | Одно случайное значение | `{{ items \| random }}` |
</details>

<details>
<summary><strong>Дата и время</strong></summary>

| Модификатор | Описание | Пример |
|-------------|----------|--------|
| `format` | Форматировать дату | `{{ date \| format:"d.m.Y" }}` |

Текущая стратегия автономной версии:

- Встроенные возможности даты и времени намеренно минимальны и сейчас ограничены только `format`.
- `format` принимает Unix timestamp и строки, которые PHP умеет разобрать через `strtotime()`.
- Если значение не удается распознать как дату/время, возвращается исходная строка без изменений.
- Carbon намеренно не входит в зависимости проекта.
- Carbon-подобные или локале-зависимые модификаторы вроде `iso_format`, `modify_date`, `days_ago`, `is_today` и `timezone` не входят в первое стабильное автономное ядро.
- Два дополнительных модификатора — `timestamp` и `ago` — доступны как явный opt-in (см. ниже) и работают на стандартных PHP-API `DateTimeImmutable`.

```php
$engine->setDateModifiers(); // регистрирует `timestamp` и `ago`
```

- `{{ date | timestamp }}` превращает строку с датой, число-timestamp или значение `DateTimeInterface` в Unix timestamp; неразбираемое значение возвращается без изменений.
- `{{ date | ago }}` выводит относительное время — `3 days ago`, `in 2 months` — по крупнейшей календарной единице; вывод детерминированный английский, поэтому включайте его, только если это подходит сайту.
- Если позже появится более богатая поддержка даты и времени, она должна строиться на стандартных PHP-типах `DateTimeImmutable`, `DateTimeInterface` и `DateTimeZone`, предпочтительно как явно подключаемое расширение.
</details>

<details>
<summary><strong>Утилиты</strong></summary>

| Модификатор | Описание | Пример |
|-------------|----------|--------|
| `is_empty` | Проверить на пустоту | `{{ items \| is_empty }}` |
| `is_array` | Проверить, что значение является массивом | `{{ items \| is_array }}` |
| `is_numeric` | Проверить, что значение числовое | `{{ value \| is_numeric }}` |
| `type_of` | Получить тип значения (`string`, `array`, `boolean`, `integer`, `double`) | `{{ value \| type_of }}` |
| `dump` | Отладочный дамп в `<pre>`-блоке (молчит без режима отладки) | `{{ value \| dump }}` |
| `md5` | MD5-хеш | `{{ email \| md5 }}` |
| `to_json` | Кодирование в JSON (`pretty` для отступов) | `{{ value \| to_json }}` |
| `to_qs` | Массив → query string | `{{ value \| to_qs }}` |
| `parse_url` | Компонент URL или весь массив частей | `{{ url \| parse_url:host }}` |
| `pathinfo` | Компонент пути или весь массив частей | `{{ path \| pathinfo:extension }}` |
| `rawurlencode` | Кодирование по RFC 3986, слэши сохраняются | `{{ url \| rawurlencode }}` |
| `urlencode` | Кодирование URL, слэши сохраняются | `{{ url \| urlencode }}` |
| `urldecode` | Декодирование URL-строки | `{{ url \| urldecode }}` |
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

Встроенные `cache`/`nocache` сейчас не входят в обязательное автономное ядро. Если нужен тег, похожий на кеширование, его можно добавить как пользовательское расширение:

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

// Доступны во всех шаблонах без явной передачи в render()
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

По умолчанию движок снисходителен: он подставляет замену, чтобы страница всё равно отрендерилась.
Строгий режим превращает каждую такую замену в `AntlersRuntimeException`:

| Ситуация | Снисходительный | Строгий |
|---|---|---|
| Неопределённая переменная | `''` | исключение |
| Защищённая переменная, тег или модификатор | `''` / значение без изменений | исключение |
| Неизвестный тег | `''` | исключение |
| Неизвестный модификатор | значение без изменений | исключение |
| `{{ loop }}` без `times`/`to` | `''` | исключение |
| `{{ scope }}` без имени | `''` | исключение |
| `{{ svg }}` без `src` или с отсутствующим файлом | `''` | исключение |
| `{{ partial }}` с путём вне корней шаблонов | `''` | исключение |
| `regex_replace` с неработающим шаблоном | исходное значение | исключение |

Каждый `AntlersRuntimeException` несёт `templateLine` — строку выражения, на котором произошёл
сбой, — и повторяет её в тексте сообщения, так что искать ошибку в длинном шаблоне делением
пополам не придётся:

```php
$engine->render("<h1>{{ title }}</h1>\n<p>{{ missing }}</p>", ['title' => 'Главная']);
// выбросит: Undefined variable: "missing" on line 2
```

Строка указывает на самое внутреннее `{{ }}`, которому можно вменить сбой, а не на блок вокруг
него: ошибка внутри `{{ if }}` или тела цикла сообщает свою собственную строку.

`??` и `???` не бросают исключение на неопределённую левую часть ни в одном режиме. Тег `dump`
и модификатор `dump` тоже не затронуты: пустой результат там означает выключенный режим
отладки — это настройка, а не сбой.

### Объекты в данных

Объекты в данных ведут себя как записи только для чтения. Что из объекта доступно шаблону,
зависит от видимости члена, и ничто из этого сам объект не меняет:

- **Публичные свойства и `__get()`** читаются всегда — `{{ user.name }}`.
- **private и protected члены невидимы.** Они дают `''`, в том числе в strict-режиме: член,
  которого шаблон не видит, — это промах, а не сбой, и никогда не сырой PHP-`Error`.

```php
$user = new User();          // public string $name, private string $token

echo $engine->render('{{ user.name }}', ['user' => $user]);  // Alice
echo $engine->render('{{ user.token }}', ['user' => $user]); // '' — никогда не ошибка
```

Вызов **метода** нужно разрешить отдельно и явно. Запись без аргументов `{{ invoice.total }}`
выполняет метод `total()`, а это исполнение кода по команде шаблона (`commit()`, `flush()` и
`save()` доступны так же), поэтому по умолчанию вызов методов запрещён — его включает
`setAllowObjectMethodCalls(true)`:

```php
// invoice.total обращается к методу total()
echo $engine->render('{{ invoice.total }}', ['invoice' => $invoice]); // '' по умолчанию

$engine->setAllowObjectMethodCalls(true);
echo $engine->render('{{ invoice.total }}', ['invoice' => $invoice]); // 42.00
```

Даже после `setAllowObjectMethodCalls(true)` вызываются только **публичные** методы; private и
protected остаются невидимыми — ровно так же, как свойства. Всё, что бросает объект, проходит
через политику strict/lenient как любой рантайм-сбой: в strict это `AntlersRuntimeException`,
а не чужое исключение, уходящее из `render()`.

### Режим отладки

Тег `dump` и модификатор `dump` молчат, пока режим отладки выключен, — так забытый в шаблоне
`{{ dump }}` не сможет раскрыть содержимое scope в продакшене. Свяжите его со своим аналогом `APP_DEBUG`:

```php
$engine->setDebug(true);

echo $engine->render('{{ dump }}');            // выведет текущий scope
echo $engine->render('{{ dump value=user }}'); // выведет одно значение
echo $engine->render('{{ user | dump }}');     // выведет значение из цепочки модификаторов
```

Внутри цикла выводится scope текущей итерации. Параметр `force="true"` игнорирует настройку:

```antlers
{{ dump force="true" value=user }}
```

### Рендерер Markdown

`markdown` — и тег, и модификатор — рендерится через
[league/commonmark](https://commonmark.thephpleague.com), тот же парсер, что используется в
Statamic, поэтому вывод совпадёт для шаблонов, перенесённых из Statamic.

Свой рендерер подключается через `MarkdownRendererInterface`:

```php
use Bugo\Antlers\Support\MarkdownRendererInterface;

$engine->setMarkdownRenderer(new class implements MarkdownRendererInterface {
    public function render(string $markdown): string
    {
        return my_own_parser($markdown);
    }
});
```

Чтобы оставить CommonMark, но изменить его настройки, передайте конвертер в `CommonMarkRenderer`:

```php
use Bugo\Antlers\Support\CommonMarkRenderer;
use League\CommonMark\CommonMarkConverter;

$engine->setMarkdownRenderer(new CommonMarkRenderer(
    new CommonMarkConverter(['html_input' => 'escape', 'max_nesting_level' => 10]),
));
```
