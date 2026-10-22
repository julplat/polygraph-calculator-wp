=== Polygraph Calculator ===
Contributors: polygraphteam
Tags: calculator, polygraph, cost, pricing, woocommerce-alternative
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Калькулятор стоимости услуг полиграфа с шорткодом, виджетом и REST API приёма заявок.

== Description ==

**Polygraph Calculator** — автономный плагин WordPress на основе калькулятора «Polygraph».

Возможности:

* Шорткод `[polygraph_calculator]` для вставки в любую страницу/запись.
* Атрибуты шорткода: `title`, `base_price`, `per_issue_price`, `max_issues`, `currency`.
* Виджет для сайдбара «Polygraph: Калькулятор».
* Страница настроек: **Настройки → Polygraph Calculator** (цены, валюта, e-mail уведомлений).
* REST API:
  * `GET /wp-json/polygraph-calculator/v1/quote` — серверный расчёт стоимости;
  * `POST /wp-json/polygraph-calculator/v1/requests` — приём заявок.
* Заявки сохраняются в админке (раздел «Заявки калькулятора») и дублируются на e-mail.
* Защита от спама: honeypot-поле.
* Локализация (текстовый домен `polygraph-calculator`).

Формула расчёта:
`(базовая цена + (кол-во тем − 1) × цена темы) × кол-во проверяемых × множитель диагностики`

== Installation ==

1. Загрузите папку `polygraph-calculator` в `/wp-content/plugins/`.
2. Активируйте плагин на странице «Плагины».
3. Настройте цены: **Настройки → Polygraph Calculator**.
4. Вставьте `[polygraph_calculator]` в нужную страницу.

== Changelog ==

= 1.0.0 =
* Первый релиз: шорткод, виджет, настройки, REST API, приём заявок.
