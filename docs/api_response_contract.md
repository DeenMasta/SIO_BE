# API Response Contract

All API endpoints must return one of these shapes.

## Success

```json
{
    "status": "success",
    "message": "Human-readable message",
    "data": {},
    "meta": {}
}
```

## Error

```json
{
    "status": "error",
    "message": "Human-readable message",
    "errors": {},
    "meta": {}
}
```

## Notes

- HTTP status code must match semantic outcome.
- `errors` is used for validation and field-level failure details.
- Do not expose stack traces or sensitive internals.
