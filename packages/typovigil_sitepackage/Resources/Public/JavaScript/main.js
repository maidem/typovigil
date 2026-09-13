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
