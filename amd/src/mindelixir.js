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
     * Return the constructor exposed by the MindElixir IIFE distribution.
     *
     * @return {Function|null} MindElixir constructor, if available.
     */
    var getConstructor = function() {
        if (typeof window.MindElixir === 'function') {
            return window.MindElixir;
        }

        if (window.MindElixir && typeof window.MindElixir.default === 'function') {
            return window.MindElixir.default;
        }

        return null;
    };

    /**
     * Resolve the constructor after the third-party script has loaded.
     *
     * @param {Function} resolve Promise resolve callback.
     * @param {Function} reject Promise reject callback.
     * @return {void}
     */
    var resolveConstructor = function(resolve, reject) {
        var constructor = getConstructor();

        if (constructor) {
            resolve(constructor);
            return;
        }

        reject(new Error('MindElixir was loaded but did not expose a constructor.'));
    };

    /**
     * Load a JavaScript file dynamically.
     *
     * @param {String} url Script URL.
     * @return {Promise}
     */
    var loadScript = function(url) {
        return new Promise(function(resolve, reject) {
            var existing = document.querySelector('script[data-studybuddy-mindelixir="1"]');

            if (getConstructor()) {
                resolve(getConstructor());
                return;
            }

            if (existing) {
                existing.addEventListener('load', function() {
                    resolveConstructor(resolve, reject);
                });
                existing.addEventListener('error', reject);
                return;
            }

            var script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.dataset.studybuddyMindelixir = '1';

            script.onload = function() {
                resolveConstructor(resolve, reject);
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
                M.cfg.wwwroot + '/local/studybuddy/lib/mindelixir/MindElixir.iife.js'
            );
        }

        return loadPromise;
    };

    return {
        load: load
    };
});
