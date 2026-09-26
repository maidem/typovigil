/*
 * Filters the project cards by status.
 *
 * Deliberately client-side: the number of projects a customer sees fits on one
 * screen, so a server round trip would only add latency. Without JavaScript the
 * pills are inert and every card stays visible, which is the honest fallback.
 */
/*
 * Closes the user menu when clicking elsewhere. The <details> element handles
 * opening, closing and keyboard access by itself — this only adds the
 * click-outside behaviour a native <details> does not have.
 */
document.addEventListener('click', function (event) {
    var menu = document.querySelector('[data-tv-user]');
    if (menu && menu.open && !menu.contains(event.target)) {
        menu.open = false;
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
        return;
    }
    var menu = document.querySelector('[data-tv-user]');
    if (menu && menu.open) {
        menu.open = false;
        menu.querySelector('summary').focus();
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var bar = document.querySelector('[data-tv-filter]');
    if (!bar) {
        return;
    }

    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-tv-severity]'));
    var empty = document.querySelector('[data-tv-empty]');

    bar.addEventListener('click', function (event) {
        var pill = event.target.closest('[data-tv-filter-value]');
        if (!pill) {
            return;
        }

        var wanted = pill.getAttribute('data-tv-filter-value');

        bar.querySelectorAll('[data-tv-filter-value]').forEach(function (other) {
            other.classList.toggle('is-active', other === pill);
        });

        var visible = 0;
        cards.forEach(function (card) {
            var show = wanted === 'all' || card.getAttribute('data-tv-severity') === wanted;
            card.hidden = !show;
            if (show) {
                visible++;
            }
        });

        if (empty) {
            empty.hidden = visible > 0;
        }
    });
});

/*
 * Filters and paginates the security advisory list client-side, same reasons
 * as the project filter above: the whole feed already sits in the page, so
 * filtering it in JS avoids a round trip per click.
 */
document.addEventListener('DOMContentLoaded', function () {
    var bar = document.querySelector('[data-tv-advisory-filter]');
    var list = document.querySelector('[data-tv-advisory-list]');
    if (!bar || !list) {
        return;
    }

    var PER_PAGE = 9999;
    var cards = Array.prototype.slice.call(list.querySelectorAll('[data-tv-advisory-type]'));
    var empty = document.querySelector('[data-tv-advisory-empty]');
    var pagination = document.querySelector('[data-tv-advisory-pagination]');
    var type = 'all';
    var page = 1;

    function matching() {
        return cards.filter(function (card) {
            return type === 'all' || card.getAttribute('data-tv-advisory-type') === type;
        });
    }

    function render() {
        var matched = matching();
        var start = (page - 1) * PER_PAGE;
        var end = start + PER_PAGE;

        cards.forEach(function (card) {
            card.hidden = true;
        });
        matched.slice(start, end).forEach(function (card) {
            card.hidden = false;
        });

        if (empty) {
            empty.hidden = matched.length > 0;
        }

        pagination.innerHTML = '';
        if (page > 1) {
            var prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'tv-card__more';
            prev.textContent = '← Neuer';
            prev.addEventListener('click', function () {
                page--;
                render();
            });
            pagination.appendChild(prev);
        }
        if (end < matched.length) {
            var next = document.createElement('button');
            next.type = 'button';
            next.className = 'tv-card__more';
            next.textContent = 'Älter →';
            next.addEventListener('click', function () {
                page++;
                render();
            });
            pagination.appendChild(next);
        }
    }

    bar.addEventListener('click', function (event) {
        var pill = event.target.closest('[data-tv-advisory-type]');
        if (!pill || !bar.contains(pill)) {
            return;
        }

        type = pill.getAttribute('data-tv-advisory-type');
        page = 1;

        bar.querySelectorAll('[data-tv-advisory-type]').forEach(function (other) {
            other.classList.toggle('is-active', other === pill);
        });

        render();
    });

    render();
});

/*
 * Filters the CLI command list by TYPO3 major, same pattern as the advisory
 * filter above minus pagination: the full command list per major is short
 * enough to show at once.
 */
document.addEventListener('DOMContentLoaded', function () {
    var bar = document.querySelector('[data-tv-cli-filter]');
    var list = document.querySelector('[data-tv-cli-list]');
    if (!bar || !list) {
        return;
    }

    var rows = Array.prototype.slice.call(list.querySelectorAll('[data-tv-cli-major]'));
    var empty = document.querySelector('[data-tv-cli-empty]');

    bar.addEventListener('click', function (event) {
        var pill = event.target.closest('[data-tv-cli-major]');
        if (!pill || !bar.contains(pill)) {
            return;
        }

        var wanted = pill.getAttribute('data-tv-cli-major');

        bar.querySelectorAll('[data-tv-cli-major]').forEach(function (other) {
            other.classList.toggle('is-active', other === pill);
        });

        var visible = 0;
        rows.forEach(function (row) {
            var show = wanted === 'all' || row.getAttribute('data-tv-cli-major') === wanted;
            row.hidden = !show;
            if (show) {
                visible++;
            }
        });

        if (empty) {
            empty.hidden = visible > 0;
        }
    });
});

/*
 * Copies a CLI command to the clipboard on click, with a short "Kopiert!"
 * confirmation on the button itself — no separate toast component needed.
 */
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-tv-copy]');
    if (!button) {
        return;
    }

    var text = button.getAttribute('data-tv-copy');
    navigator.clipboard.writeText(text).then(function () {
        var original = button.dataset.tvCopyLabel || button.innerHTML;
        button.dataset.tvCopyLabel = original;
        button.classList.add('is-copied');
        button.textContent = 'Kopiert!';
        setTimeout(function () {
            button.innerHTML = button.dataset.tvCopyLabel;
            button.classList.remove('is-copied');
        }, 1200);
    });
});
