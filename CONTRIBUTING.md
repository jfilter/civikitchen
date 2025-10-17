# Contributing to CiviCRM Buildkit Docker Setup

Thank you for your interest in contributing! This document provides guidelines for contributing to this project.

## How to Contribute

### Reporting Issues

If you encounter a problem:

1. **Search existing issues** to see if it has already been reported
2. **Check the documentation** in [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)
3. **Create a new issue** with:
   - Clear description of the problem
   - Steps to reproduce
   - Your environment (OS, Docker version, PHP version, CiviCRM version)
   - Relevant logs (use `docker-compose logs civicrm`)

### Suggesting Enhancements

Feature requests are welcome! Please:

1. Check if the feature has already been suggested
2. Explain the use case and benefits
3. Provide examples if applicable

## Pull Requests

### Before Submitting

1. **Fork the repository**
2. **Create a feature branch** from `main`
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Make your changes**
4. **Test your changes** thoroughly (see Testing Requirements below)
5. **Update documentation** if needed

### Testing Requirements

All changes must pass the existing test suite. Run tests locally before submitting:

```bash
# Run all tests
npm test

# Test with different PHP versions
npm run test:all-php

# Test with different CiviCRM versions
npm run test:all-civicrm
```

See [docs/TESTING.md](docs/TESTING.md) for comprehensive testing documentation.

### Submitting Your Pull Request

1. **Push to your fork**
   ```bash
   git push origin feature/your-feature-name
   ```

2. **Create a pull request** with:
   - Clear title describing the change
   - Description of what changed and why
   - Reference to related issues (if any)
   - Test results showing all tests pass

3. **Wait for CI checks** to complete
   - PR checks test PHP 8.2 + CiviCRM 5.74.0
   - All tests must pass

### Code Guidelines

- **Shell scripts**: Use bash best practices, include comments for complex logic
- **Docker**: Follow Docker best practices (minimize layers, use .dockerignore)
- **Documentation**: Update relevant docs in the `docs/` directory
- **Commit messages**: Use clear, descriptive commit messages

Example commit message format:
```
Add support for PHP 8.4

- Update Dockerfile to support PHP 8.4
- Add PHP 8.4 to test matrix
- Update documentation
```

## Development Workflow

1. **Start the environment**
   ```bash
   docker-compose up -d
   ```

2. **Make your changes** to Dockerfile, entrypoint.sh, or other files

3. **Test locally**
   ```bash
   # Rebuild if needed
   docker-compose build --no-cache
   docker-compose up -d

   # Run tests
   npm test
   ```

4. **Check logs**
   ```bash
   docker-compose logs -f civicrm
   ```

## Project Structure

```
.
├── Dockerfile              # Base image with buildkit
├── docker-compose.yml      # Service orchestration
├── entrypoint.sh          # Buildkit installation script
├── .env.example           # Environment template
├── tests/                 # Playwright E2E tests
│   ├── e2e/*.spec.ts     # Test files
│   └── helpers.ts        # Test utilities
├── docs/                  # Documentation
│   ├── TESTING.md        # Testing guide
│   ├── TROUBLESHOOTING.md # Common issues
│   └── ADVANCED.md       # Advanced usage
└── .github/workflows/    # CI/CD workflows
```

## Need Help?

- Check the [documentation](docs/)
- Review existing [issues and pull requests](../../issues)
- Review [CiviCRM Developer Guide](https://docs.civicrm.org/dev/en/latest/)

## Code of Conduct

- Be respectful and constructive
- Focus on what is best for the community
- Show empathy towards other contributors

Thank you for contributing!
