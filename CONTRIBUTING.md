# NebulaCMS Contribution Guidelines
Thank you for considering contributing to NebulaCMS! We greatly appreciate every contribution from the community.

## 🤝 How to Contribute

### 1. Fork & Clone
- Fork repositori NebulaCMS
- Clone your fork to local
```bash
git clone https://github.com/AngkasaLabs/nebula-cms.git
cd nebula-cms
```

### 2. Setup Development Environment
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

### 3. Create a Branch
```bash
git checkout -b feature-name
# atau
git checkout -b fix-issue
```

### 4. Coding Guidelines
- Follow PSR-12 for PHP
- Use TypeScript for frontend code
- Ensure the code is formatted with Prettier
- Write tests for new features

Run the same gates as CI before opening a pull request:

```bash
php artisan test            # SQLite locally; CI also runs MySQL 8.4
composer analyse            # Larastan level 5 (baseline tracked in phpstan-baseline.neon)
php vendor/bin/pint --test  # PHP formatting; use `php vendor/bin/pint` to fix
npm run types               # tsc --noEmit
npm run format:check        # Prettier check; use `npm run format` to fix
npm run lint                # ESLint check; use `npm run lint:fix` to fix
npm run build
```

Database-specific behaviour (full-text search, JSON columns) is only covered by the MySQL
job, so mention in the PR when a change depends on the database driver. Full-text search has
a dedicated suite that needs a MySQL connection:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=nebula_test \
DB_USERNAME=root DB_PASSWORD=root php vendor/bin/pest -c phpunit.integration.xml
```

### 5. Commit Guidelines
Format commit message:
```
type(scope): description

[optional body]
[optional footer]
```

Types:
- feat: New feature
- fix: Bug fix
- docs: Documentation changes
- style: Formatting, missing semicolons, etc.
- refactor: Code refactoring
- test: Add/update tests
- chore: Changes to build process or tools

### 6. Submit Pull Request
- Push to your fork
- Create a Pull Request to the main repository
- Describe the changes clearly
- Link to related issues if any

## 🐛 Reporting Bugs
- Use the bug report template
- Include reproduction steps
- Include expected vs actual behavior
- Include screenshots if possible

## 💡 Suggesting Features
- Use the feature request template
- Explain the use case
- Explain the proposed solution
- Consider alternatives you have thought of

## 📝 Documentation
- Documentation is located in the `/docs` folder
- Use Markdown
- Follow the documentation writing guidelines

## ⚖️ License
By contributing, you agree that your contributions will be licensed under the MIT License.

## 💬 Questions?
- Create an issue on GitHub
- Send an email to maintainers@angkasalabs.com 
