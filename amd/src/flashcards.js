// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * StudyBuddy flashcards.
 *
 * @module     local_studybuddy/flashcards
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/templates'], function(Templates) {
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
            var renderSequence = 0;

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
            var meta = container.querySelector('[data-region="flashcard-meta"]');
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

            var renderMeta = function(card, sequence) {
                var cardCitations = Array.isArray(card.citations) ? card.citations : [];
                var context = {
                    hashint: Boolean(card.hint),
                    hintlabel: hintLabel,
                    hint: String(card.hint || ''),
                    hascitations: cardCitations.length > 0,
                    citationslabel: citationsLabel,
                    citations: cardCitations.map(function(citation) {
                        return {label: String(citation.label || '')};
                    })
                };

                return Templates.renderForPromise('local_studybuddy/flashcard_meta', context).then(function(result) {
                    if (sequence === renderSequence) {
                        Templates.replaceNodeContents(meta, result.html, result.js);
                    }
                    return result;
                });
            };

            var render = function() {
                var card = cards[index] || {};
                var sequence = ++renderSequence;

                revealed = false;
                progress.textContent = card.progresslabel || ('Flashcard ' + (index + 1) + ' of ' + cards.length);
                front.textContent = String(card.front || '');
                answer.textContent = String(card.back || '');

                back.classList.add('d-none');
                revealButton.classList.remove('d-none');
                revealButton.textContent = revealLabel;

                previousButton.disabled = index === 0;
                nextButton.disabled = index === cards.length - 1;

                return renderMeta(card, sequence);
            };

            revealButton.addEventListener('click', function() {
                revealed = !revealed;
                back.classList.toggle('d-none', !revealed);
                revealButton.textContent = revealed ? hideLabel : revealLabel;
            });

            previousButton.addEventListener('click', function() {
                if (index > 0) {
                    index--;
                    render().catch(window.console.error);
                }
            });

            nextButton.addEventListener('click', function() {
                if (index < cards.length - 1) {
                    index++;
                    render().catch(window.console.error);
                }
            });

            render().catch(window.console.error);
        });
    };

    return {
        init: init
    };
});
