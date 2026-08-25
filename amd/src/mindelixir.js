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
 * MindElixir loader for StudyBuddy.
 *
 * @module     local_studybuddy/mindelixir
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    var loadPromise = null;

    /**
     * Load a JavaScript file dynamically.
     *
     * @param {String} url Script URL.
     * @return {Promise}
     */
    var loadScript = function(url) {
        return new Promise(function(resolve, reject) {
            var existing = document.querySelector('script[data-studybuddy-mindelixir="1"]');

            if (window.MindElixir) {
                resolve(window.MindElixir);
                return;
            }

            if (existing) {
                existing.addEventListener('load', function() {
                    resolve(window.MindElixir);
                });
                existing.addEventListener('error', reject);
                return;
            }

            var script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.dataset.studybuddyMindelixir = '1';

            script.onload = function() {
                if (window.MindElixir) {
                    resolve(window.MindElixir);
                } else {
                    reject(new Error('MindElixir was loaded but window.MindElixir is not available.'));
                }
            };

            script.onerror = function() {
                reject(new Error('Unable to load MindElixir from ' + url));
            };

            document.head.appendChild(script);
        });
    };

    /**
     * Load MindElixir once.
     *
     * @return {Promise}
     */
    var load = function() {
        if (!loadPromise) {
            loadPromise = loadScript(
                M.cfg.wwwroot + '/local/studybuddy/lib/mindelixir/MindElixir.iife.min.js'
            );
        }

        return loadPromise;
    };

    return {
        load: load
    };
});
