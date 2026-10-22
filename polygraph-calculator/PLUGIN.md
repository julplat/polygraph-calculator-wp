# Polygraph Calculator — плагин WordPress

Автономный плагин, созданный на основе калькулятора «Polygraph» (см. ТЗ в корне репозитория).

## Структура

```
polygraph-calculator/
├── polygraph-calculator.php        # Главный файл плагина (заголовок, init, ассеты)
├── readme.txt                      # Метаданные для WordPress.org
├── includes/
│   ├── class-pgcalc-settings.php   # Настройки + страница в админке
│   ├── class-pgcalc-shortcode.php  # Шорткод [polygraph_calculator]
│   ├── class-pgcalc-widget.php     # Виджет для сайдбара
│   └── class-pgcalc-rest.php       # REST API (расчёт + заявки), CPT «Заявки»
├── templates/
│   └── calculator.php              # HTML-шаблон калькулятора
├── assets/
│   ├── css/frontend.css            # Стили фронтенда (БЭМ)
│   ├── css/admin.css               # Стили страницы настроек
│   └── js/calculator.js            # Клиентская логика + отправка заявок
└── languages/                      # .po/.mo файлы переводов (заглушка)
```

## Установка

1. Скопируйте папку `polygraph-calculator/` в `wp-content/plugins/`.
2. В админке WordPress активируйте плагин «Polygraph Calculator».
3. Откройте **Настройки → Polygraph Calculator** и задайте цены/валюту/e-mail.
4. Вставьте шорткод `[polygraph_calculator]` в страницу или используйте виджет
   «Polygraph: Калькулятор».

### Сборка ZIP для загрузки через админку

```bash
cd polygraph-calculator && zip -r ../polygraph-calculator.zip . -x "*.DS_Store"
```

## Формула расчёта

```
Итого = (base_price + (issues − 1) × per_issue_price) × persons × multiplier_диагностики
```

Все параметры настраиваются в админке; каждый калькулятор можно переопределить
атрибутами шорткода:

```
[polygraph_calculator title="Сколько стоит проверка?" base_price="7000" per_issue_price="2000" max_issues="15" currency="RUB"]
```

## REST API

| Метод | Эндпоинт | Назначение |
|-------|----------|------------|
| GET   | `/wp-json/polygraph-calculator/v1/quote?issues=5&persons=2&pre_post=1` | Серверный расчёт стоимости |
| POST  | `/wp-json/polygraph-calculator/v1/requests` | Создание заявки (имя, телефон/e-mail, комментарий) |

Заявки сохраняются в разделе админки **«Заявки калькулятора»** и отправляются
на указанный e-mail. Защита от спама — honeypot-поле.

## Безопасность

- Все выводимые данные экранируются (`esc_html`, `esc_attr`, `wp_kses_post`).
- Входящие данные санитизируются (`sanitize_text_field`, `absint`, `sanitize_email`).
- REST-запросы используют nonce `wp_rest`; настройки доступны только `manage_options`.
- Прямой доступ к PHP-файлам запрещён проверкой `ABSPATH`.

## Требования

- WordPress ≥ 5.8, PHP ≥ 7.4.
