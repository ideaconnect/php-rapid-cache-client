# GitHub Workflow Documentation

## Tests Workflow

The `tests.yml` workflow automatically runs on:
- Push to `main`, `develop`, or `4.x` branches
- Pull requests targeting `main`, `develop`, or `4.x` branches

### What it tests:

1. **Unit Tests with 100% Coverage Requirement**
   - Runs PHPUnit tests with Xdebug coverage
   - Validates that both method and line coverage are exactly 100%
   - Generates coverage reports (HTML, Clover XML)

2. **Functional Tests**
   - Runs Behat functional tests against a live Redis/Valkey instance
   - Tests real-world usage scenarios

### PHP Version Matrix:
- PHP 8.2
- PHP 8.3
- PHP 8.4

### Services:
- **Redis/Valkey**: Uses `valkey/valkey:7.2-alpine` on port 6380
- **Health checks**: Ensures Redis is ready before running tests

### Coverage Requirements:
- **Methods**: Must be 100.00%
- **Lines**: Must be 100.00%

If coverage drops below 100%, the workflow will fail with a clear error message.

### Artifacts:
On failure, uploads coverage reports for debugging:
- `coverage_output.txt` - Console output
- `coverage.xml` - Clover XML format
- `report/` - HTML coverage report

### Local Testing:
To run the same tests locally:
```bash
# Unit tests with coverage
composer test:unit

# BDD (Behat) tests
composer test:bdd

# All tests
composer test
```
