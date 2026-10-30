# Varbase AI Figma

[![pipeline status](https://git.drupalcode.org/project/varbase_ai_figma/badges/1.0.x/pipeline.svg)](https://git.drupalcode.org/project/varbase_ai_figma/-/pipelines)
[![Varbase AI Figma](https://img.shields.io/badge/Varbase%20AI%20Figma-1.0.6-0d6efc?labelColor=001d38&style=flat-square)](https://git.drupalcode.org/project/varbase_ai_figma/-/pipelines?ref=1.0.6)
[![Automated Functional Testing](https://git.drupalcode.org/project/varbase_project/badges/11.0.x/pipeline.svg)](https://git.drupalcode.org/project/varbase_project/-/pipelines)

Turn a Figma design into a ready-made Drupal Canvas page. Paste a Figma link and
the module builds a matching page from your site's own components, styled with the
design's colours and fonts and filled with its real text - no coding.

## Documentation

Read the [Varbase AI Figma Base documentation](https://docs.varbase.vardot.com/developers/understanding-varbase/varbase-ai-recipes/varbase-ai-figma-base) on the Varbase docs site.

## Install

```bash
ddev composer require drupal/varbase_ai_figma:~1
ddev drush en varbase_ai_figma -y
```

Most sites apply the [Varbase AI Figma Base](https://www.drupal.org/project/varbase_ai_figma_base) recipe instead,
which installs this module and wires it into the Drupal Canvas AI Orchestrator.
