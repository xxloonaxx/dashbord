/*
 * Gemeinsame Live-Suche für die Link-Hubs.
 * - Mehrere Suchbegriffe (alle müssen passen), Kategorie-Namen werden mitdurchsucht
 * - Trefferzähler + Hinweis bei 0 Treffern
 * - Tastatur: "/" fokussiert die Suche, Esc leert sie, Enter öffnet den ersten Treffer
 */
(function () {
    const input = document.getElementById('searchInput');
    if (!input) return;

    const sections = Array.from(document.querySelectorAll('.category-section'));
    const total = document.querySelectorAll('.card').length;

    const counter = document.createElement('div');
    counter.className = 'search-counter';
    input.insertAdjacentElement('afterend', counter);

    const noResults = document.createElement('div');
    noResults.className = 'no-results';
    noResults.textContent = 'Keine Treffer – anderen Suchbegriff probieren.';
    noResults.hidden = true;
    (document.getElementById('contentWrapper') || document.body).appendChild(noResults);

    function filterCards() {
        const terms = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
        let visible = 0;

        sections.forEach(section => {
            const titleEl = section.querySelector('.category-title > span:first-child') || section.querySelector('.category-title');
            const catName = titleEl ? titleEl.textContent : '';
            let sectionVisible = 0;
            section.querySelectorAll('.card').forEach(card => {
                const haystack = (card.textContent + ' ' + catName + ' ' + card.getAttribute('href')).toLowerCase();
                const match = terms.every(t => haystack.includes(t));
                (card.closest('.card-wrap') || card).style.display = match ? '' : 'none';
                if (match) sectionVisible++;
            });
            section.style.display = (sectionVisible || !terms.length) ? '' : 'none';
            const empty = section.querySelector('.hub-empty');
            if (empty) empty.style.display = terms.length ? 'none' : '';
            visible += sectionVisible;
        });

        counter.textContent = terms.length ? visible + ' von ' + total + ' Links' : total + ' Links';
        noResults.hidden = visible !== 0 || !terms.length;
    }

    // Wird auch vom alten onkeyup-Attribut aufgerufen
    window.filterCards = filterCards;

    input.addEventListener('input', filterCards);
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { input.value = ''; filterCards(); }
        if (e.key === 'Enter') {
            const first = Array.from(document.querySelectorAll('.card')).find(c => (c.closest('.card-wrap') || c).style.display !== 'none');
            if (first && input.value.trim()) window.open(first.href, '_blank', 'noopener');
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === '/' && document.activeElement !== input && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
            e.preventDefault();
            input.focus();
        }
    });

    filterCards();
})();
