PY      := .venv/bin/python
DOCS_PORT ?= 8080

.PHONY: install test test-fast lint fmt docs docs-css docs-install media clean

install:            ## venv + package en mode éditable + outils de dev
	python3 -m venv .venv
	$(PY) -m pip install -e '.[dev]'

test:               ## toute la suite (unit, intégration, TUI)
	$(PY) -m pytest

test-fast:          ## sans le scénario TUI
	$(PY) -m pytest -m "not tui"

lint:
	$(PY) -m ruff check src tests scripts

fmt:
	$(PY) -m ruff check --fix src tests scripts

docs-install:       ## dépendances PHP de la doc (scssphp)
	cd docs && composer install

docs-css:           ## compile docs/scss -> docs/public/assets/css/app.css
	php docs/bin/build-css.php

media:              ## captures et GIF du README dans media/ (faux Discord, pip install -e '.[media]')
	$(PY) scripts/make_media.py media

docs:               ## sert la documentation sur http://localhost:$(DOCS_PORT)
	php -S localhost:$(DOCS_PORT) -t docs/public docs/public/router.php

clean:
	rm -rf .pytest_cache .ruff_cache build dist src/*.egg-info
	find . -name __pycache__ -type d -prune -exec rm -rf {} +
