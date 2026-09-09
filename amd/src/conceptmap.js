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
 * StudyBuddy MindElixir concept map renderer.
 *
 * @module     local_studybuddy/conceptmap
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['local_studybuddy/mindelixir'], function(MindElixirLoader) {
    var counter = 0;
    var viewportRatio = 0.9;
    var viewportPadding = 24;

    var getMindElixir = function() {
        if (MindElixirLoader && typeof MindElixirLoader.load === 'function') {
            return MindElixirLoader.load();
        }

        return Promise.resolve(MindElixirLoader);
    };

    var escapeHtml = function(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    var renderTopic = function(topic) {
        var lines = String(topic || '').split('\n');
        var title = escapeHtml(lines.shift() || '');
        var description = escapeHtml(lines.join('\n').trim());
        var html = '<span class="d-block local-studybuddy-mindmap-topic-title">' + title + '</span>';

        if (description !== '') {
            html += '<span class="d-block mt-1 local-studybuddy-mindmap-topic-description">' + description + '</span>';
        }

        return html;
    };

    /**
     * Resize the MindElixir viewport and centre the root node.
     *
     * @param {HTMLElement} canvas Canvas wrapper.
     * @param {Object} mind MindElixir instance.
     */
    var fitViewportToMap = function(canvas, mind) {
        var nodes = canvas.querySelector('me-nodes');
        var container = canvas.querySelector('.map-container');
        var root = canvas.querySelector('me-root me-tpc');
        var parent = canvas.parentElement;

        if (!nodes || !container || !mind.map) {
            return;
        }

        if (typeof mind.toCenter === 'function') {
            mind.toCenter();
        }

        var parentWidth = parent ? parent.getBoundingClientRect().width : window.innerWidth;
        var nodesRect = nodes.getBoundingClientRect();
        var width = Math.ceil(Math.min(nodesRect.width + viewportPadding, parentWidth * viewportRatio));
        var height = Math.ceil(nodesRect.height + viewportPadding);

        if (width <= 0 || height <= 0) {
            return;
        }

        canvas.style.width = width + 'px';
        canvas.style.maxWidth = '100%';
        canvas.style.height = height + 'px';
        container.style.width = width + 'px';
        container.style.height = height + 'px';

        if (root) {
            if (typeof mind.toCenter === 'function') {
                mind.toCenter();
            }

            if (typeof mind.scrollIntoView === 'function') {
                mind.scrollIntoView(root, true);
            }

            if (typeof mind.selectNode === 'function') {
                mind.selectNode(root, true);
            }
        }
    };

    var renderMap = function(canvas, layout, MindElixir) {
        var rawdata = canvas.dataset.mindmap || '{}';
        var data;

        try {
            data = JSON.parse(rawdata);
        } catch (error) {
            canvas.classList.add('local-studybuddy-mindmap-error');
            canvas.setAttribute('aria-busy', 'false');
            window.console.error(error);
            return;
        }

        if (!canvas.id) {
            counter++;
            canvas.id = 'local-studybuddy-mindmap-' + counter;
        }

        canvas.innerHTML = '';

        var mind = new MindElixir({
            el: '#' + canvas.id,
            editable: false,
            nodeMenu: true,
            toolBar: false,
            contextMenu: false,
            compact: true,
            direction: layout === 'horizontal' ? MindElixir.RIGHT : MindElixir.SIDE,
            markdown: renderTopic
        });

        mind.init(data);
        canvas.setAttribute('aria-busy', 'false');
        window.setTimeout(function() {
            fitViewportToMap(canvas, mind);
            window.requestAnimationFrame(function() {
                fitViewportToMap(canvas, mind);
                window.requestAnimationFrame(function() {
                    fitViewportToMap(canvas, mind);
                });
            });
        }, 80);
    };

    var init = function(root) {
        root = root || document;

        root.querySelectorAll('[data-region="conceptmap"]').forEach(function(map) {
            var canvas = map.querySelector('[data-region="studybuddy-mindmap"]');

            if (!canvas || map.dataset.studybuddyInitialised === '1') {
                return;
            }

            map.dataset.studybuddyInitialised = '1';

            getMindElixir().then(function(MindElixir) {
                renderMap(canvas, 'vertical', MindElixir);

                map.querySelectorAll('[data-conceptmap-layout]').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var layout = button.dataset.conceptmapLayout || 'vertical';

                        map.querySelectorAll('[data-conceptmap-layout]').forEach(function(item) {
                            item.classList.remove('active');
                            item.setAttribute('aria-pressed', 'false');
                        });
                        button.classList.add('active');
                        button.setAttribute('aria-pressed', 'true');

                        renderMap(canvas, layout, MindElixir);
                    });
                });
                return MindElixir;
            }).catch(function(error) {
                canvas.classList.add('local-studybuddy-mindmap-error');
                canvas.setAttribute('aria-busy', 'false');
                window.console.error(error);
            });
        });
    };

    return {
        init: init
    };
});
