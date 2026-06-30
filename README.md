# Curdder

Curdder is a lightweight PHP CRUD generator.

It can:
- inspect an existing database schema
- generate a CRUD-ready config for APIs, websites, or both
- accept explicit table and foreign-key join instructions
- render a small built-in CRUD UI and JSON API

## Install

```bash
composer require curdder/curdder
```

## Generate from a database

```bash
php vendor/bin/curdder generate \
  --dsn="mysql:host=127.0.0.1;dbname=app;charset=utf8mb4" \
  --user=root \
  --password=secret \
  --output=./generated-app \
  --mode=both
```

## Limit tables

```bash
php vendor/bin/curdder generate \
  --dsn="sqlite:/path/to/database.sqlite" \
  --table=users \
  --table=posts \
  --output=./generated-app
```

## Explicit joins

You can define joins when the database does not expose the relationship the way you want.

```bash
php vendor/bin/curdder generate \
  --dsn="mysql:host=127.0.0.1;dbname=app" \
  --join="posts.user_id=users.id:name" \
  --join="posts.category_id=categories.id:title"
```

Join rule format:

```text
table.column=related_table.related_column:label_column
```

## Spec file

Use a JSON or PHP spec file when you want to describe tables, labels, and relations in one place.

```json
{
  "name": "Blog Admin",
  "tables": [
    "users",
    {
      "name": "posts",
      "label": "Posts",
      "search_columns": ["title", "slug"]
    }
  ],
  "relations": [
    {
      "table": "posts",
      "column": "user_id",
      "references": "users.id",
      "label_column": "name"
    }
  ]
}
```

Then run:

```bash
php vendor/bin/curdder generate \
  --dsn="mysql:host=127.0.0.1;dbname=app" \
  --spec=./crudder.json \
  --output=./generated-app
```

## Use the generated app

The generated project includes:
- `crudder.php` config
- `public/index.php` for web CRUD
- `public/api.php` for JSON CRUD

Serve it with:

```bash
php -S 127.0.0.1:8000 -t public
```

Then open:
- `http://127.0.0.1:8000/?resource=users`
- `http://127.0.0.1:8000/api.php?resource=users`
