# Podcast plugin instructions

This repository owns the production Podcast Broadcast plugin. It depends on
`stashd/plugin-sdk`, but must not depend on Stashd core or Wasmtime reference
code. Use PHP 8.5, PER-CS3, strict PSR-4, and code-as-paragraphs vertical
spacing. Run `composer lint`, `composer test`, and `composer test:static`.
