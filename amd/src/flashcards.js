/**
 * StudyBuddy flashcards.
 *
 * @module     local_studybuddy/flashcards
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    /**
     * Escape HTML.
     *
     * @param {String} value Raw value.
     * @return {String}
     */
    var escapeHtml = function(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    /**
     * Decode HTML entities from a data attribute value.
     *
     * @param {String} value Encoded value.
     * @return {String}
     */
    var decodeHtml = function(value) {
        var textarea = document.createElement('textarea');
        textarea.innerHTML = String(value || '');
        return textarea.value;
    };

    /**
     * Initialise flashcards.
     *
     * @param {HTMLElement|undefined} root Root element.
     */
    var init = function(root) {
        root = root || document;

        root.querySelectorAll('[data-region="studybuddy-flashcards"]').forEach(function(container) {
            var cards = [];
            var index = 0;
            var revealed = false;

            try {
                cards = JSON.parse(decodeHtml(container.dataset.cards || '[]'));
            } catch (error) {
                window.console.error(error);
                return;
            }

            var progress = container.querySelector('[data-region="flashcard-progress"]');
            var front = container.querySelector('[data-region="flashcard-front"]');
            var back = container.querySelector('[data-region="flashcard-back"]');
            var answer = container.querySelector('[data-region="flashcard-answer"]');
            var hint = container.querySelector('[data-region="flashcard-hint"]');
            var citations = container.querySelector('[data-region="flashcard-citations"]');
            var revealButton = container.querySelector('[data-action="reveal-answer"]');
            var previousButton = container.querySelector('[data-action="previous-card"]');
            var nextButton = container.querySelector('[data-action="next-card"]');
            var revealLabel = container.dataset.revealLabel || 'Reveal answer';
            var hideLabel = container.dataset.hideLabel || 'Hide answer';
            var hintLabel = container.dataset.hintLabel || 'Hint';
            var citationsLabel = container.dataset.citationsLabel || 'Sources';

            if (!cards.length) {
                return;
            }

            var render = function() {
                var card = cards[index] || {};

                revealed = false;
                progress.textContent = card.progresslabel || ('Flashcard ' + (index + 1) + ' of ' + cards.length);
                front.innerHTML = escapeHtml(card.front);
                answer.innerHTML = escapeHtml(card.back).replace(/\n/g, '<br>');

                if (card.hint) {
                    hint.classList.remove('d-none');
                    hint.innerHTML = '<strong>' + escapeHtml(hintLabel) + ':</strong> ' + escapeHtml(card.hint);
                } else {
                    hint.classList.add('d-none');
                    hint.innerHTML = '';
                }

                if (card.citations && card.citations.length) {
                    citations.classList.remove('d-none');
                    citations.innerHTML = '<strong>' + escapeHtml(citationsLabel) + '</strong> ' +
                        card.citations.map(function(citation) {
                        return '<span class="badge badge-light rounded-pill ml-1">' + escapeHtml(citation.label) + '</span>';
                    }).join('');
                } else {
                    citations.classList.add('d-none');
                    citations.innerHTML = '';
                }

                back.classList.add('d-none');
                revealButton.classList.remove('d-none');
                revealButton.textContent = revealLabel;

                previousButton.disabled = index === 0;
                nextButton.disabled = index === cards.length - 1;
            };

            revealButton.addEventListener('click', function() {
                revealed = !revealed;
                back.classList.toggle('d-none', !revealed);
                revealButton.textContent = revealed ? hideLabel : revealLabel;
            });

            previousButton.addEventListener('click', function() {
                if (index > 0) {
                    index--;
                    render();
                }
            });

            nextButton.addEventListener('click', function() {
                if (index < cards.length - 1) {
                    index++;
                    render();
                }
            });

            render();
        });
    };

    return {
        init: init
    };
});
