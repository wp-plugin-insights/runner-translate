# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Purpose

This runner analyzes WordPress plugin translation quality. It's the reference implementation for the `runner-NNNN` pattern in the wp-plugin-insights project.

## Architecture

**Message Flow:**
```
RabbitMQ → Application → Runner → JobProcessor → Validators → ReportBuilder → ReportPublisher → RabbitMQ
```

**Key Components:**

1. **Application/Runner** - RabbitMQ consumer layer
   - Consumes from `RABBITMQ_INPUT_QUEUE`
   - Publishes to `RABBITMQ_REPORT_EXCHANGE` with routing key `runner-report`
   - Manual acknowledgements: ack on success, reject invalid input (no requeue), nack runtime failures (requeue)

2. **JobProcessor** - Main orchestrator
   - Dual-source behavior: calls translate.wordpress.org API for `source: "wordpress.org"`, scans local files for others
   - Instantiates and runs all validators
   - Builds issues with source-dependent severity (wordpress.org plugins get stricter text domain rules)

3. **ReportBuilder** - Generic report builder (NOT opinionated)
   - Designed to be reusable across other runners
   - Accepts: score, metrics, capabilities, issues, details, presentation
   - Does NOT contain translation-specific logic

4. **TranslationScorer** - Translation-specific scoring
   - Defines major locales: de_DE, fr_FR, es_ES, it_IT, pt_BR, ja, zh_CN, nl_NL, ru_RU, ko_KR
   - Calculates grades (A+, A, B, C, D, F) based on coverage

5. **Validators** - Modular checks following a common pattern
   - Each validator is independent and returns structured data
   - All validators scan PHP files using `findPhpFiles()` which excludes vendor/node_modules/languages
   - See "Validators" section below for details

## Contracts

**Input** (from RabbitMQ):
```json
{
  "plugin": "akismet",
  "version": "1.0",
  "source": "wordpress.org",
  "src": "/path/to/unpacked/plugin"
}
```

**Output** (to RabbitMQ):
```json
{
  "runner": "translate",
  "plugin": "akismet",
  "version": "1.0",
  "source": "wordpress.org",
  "src": "/path/to/unpacked/plugin",
  "report": {
    "score": {...},
    "metrics": {...},
    "capabilities": {...},
    "issues": {...},
    "details": {...},
    "presentation": {...}
  },
  "received_at": "2026-03-20T10:00:00+00:00",
  "completed_at": "2026-03-20T10:00:00+00:00"
}
```

The `source` field determines behavior: `"wordpress.org"` triggers API calls to translate.wordpress.org; anything else scans local files.

## Setup

```bash
composer install
cp .env.example .env  # Configure RabbitMQ settings
php bin/runner        # Start consuming messages
```

## Testing

Test without RabbitMQ by piping JSON to `bin/process-message`:

### Test with wordpress.org plugin

```bash
echo '{"plugin":"akismet","version":"5.3.3","source":"wordpress.org","src":"/tmp/plugins/akismet"}' | php bin/process-message
```

Fetches from translate.wordpress.org API. Text domain issues are HIGH severity.

### Test with local plugin containing issues

```bash
mkdir -p /tmp/test-plugin && cat > /tmp/test-plugin/test-plugin.php << 'EOF'
<?php
/**
 * Plugin Name: Test Plugin
 * Text Domain: test-domain
 */
$message = _e('Hello World', 'test-domain');  // Wrong: _e() in assignment
echo __('Welcome Message', 'test-domain');    // Wrong: no escaping
load_plugin_textdomain('test-domain', false, dirname(plugin_basename(__FILE__)) . '/languages');  // Wrong: not hooked
$label = __('Read', 'test-domain');           // Ambiguous without context
$text = __('You have %d items', 'test-domain');  // Missing translator comment
EOF

echo '{"plugin":"test-plugin","version":"1.0","source":"other","src":"/tmp/test-plugin"}' | php bin/process-message
```

Scans local files. Should detect 6 issues: 2 high (wrong function usage), 2 medium (text domain mismatch, load hook), 1 low (missing context), 1 trivial (missing translator comment).

## Validators

All validators follow this pattern:
- Return structured arrays with `issues`, counts, and detailed findings
- Use recursive file scanning with `findPhpFiles()` that excludes vendor/node_modules/languages
- Parse PHP code with regex (not AST) for function calls and parameters
- Handle nested parentheses using `extractBalancedParentheses()` pattern

**Key validators:**

- **LoadHookValidator** - Searches backwards in code to find `add_action()` context for `load_plugin_textdomain()` calls
- **TranslationBestPractices** - Multiple regex-based checks for wrong function usage, missing escaping, placeholders, contexts
- **TextDomainValidator** - Extracts text domain parameter from function calls using `getTextDomainParameter()`, tracks usage. Note: Text Domain header is optional since WordPress 4.6 (WordPress uses plugin slug automatically)
- **JavaScriptI18nScanner** - Dual-mode regex patterns for `wp.i18n.__()` and destructured `__()` forms
- **UntranslatedStringScanner** - **Very conservative** - only checks specific contexts (wp_die, WP_Error, etc.) to minimize false positives
- **TranslationFileParser** - Finds `load_plugin_textdomain()` to determine registered translation directory, parses .po files

## Important Domain Knowledge

**WordPress Locale Format:** Always uses underscores (de_DE, pt_BR), never hyphens. No normalization needed.

**Translation Function Parameter Positions:**
- `__()`, `_e()`, `esc_html__()`, `esc_html_e()`: domain is parameter 1
- `_x()`, `_ex()`, `esc_html_x()`: domain is parameter 2
- `_n()`, `_nx()`: domain is parameter 3

**Balanced Parentheses Parsing:** Critical for extracting function parameters when calls are nested like:
```php
load_plugin_textdomain('domain', false, dirname(plugin_basename(__FILE__)) . '/languages')
```

**ReportBuilder Contract:** Must remain generic. Translation-specific logic goes in TranslationScorer or JobProcessor, never in ReportBuilder.

## Severity Levels

Issue severity varies by plugin source. Text domain mismatch (when header is declared but doesn't match plugin slug) is HIGH severity for wordpress.org plugins but MEDIUM for others. Missing Text Domain header is NOT an issue since WordPress 4.6 automatically uses the plugin slug. See JobProcessor's `doAction()` method for the full mapping.
