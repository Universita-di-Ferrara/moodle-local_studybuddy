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
 * Keeps the Vertex AI base URL in sync with the selected location.
 *
 * @module local_studybuddy/settings
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * Returns the standard Vertex AI endpoint for a location.
     *
     * @param {String} location Vertex AI location.
     * @returns {String} Vertex AI base URL.
     */
    var getBaseUrl = function(location) {
        if (location === 'global') {
            return 'https://aiplatform.googleapis.com';
        }

        if (location === 'eu' || location === 'us') {
            return 'https://aiplatform.' + location + '.rep.googleapis.com';
        }

        return 'https://' + location + '-aiplatform.googleapis.com';
    };

    return {
        /**
         * Initialise the provider settings behaviour.
         */
        init: function() {
            var locationInput = document.querySelector('[name$="vertexailocation"]');
            var baseUrlInput = document.querySelector('[name$="vertexaibaseurl"]');

            if (!locationInput || !baseUrlInput) {
                return;
            }

            locationInput.addEventListener('change', function() {
                if (!locationInput.value) {
                    return;
                }

                baseUrlInput.value = getBaseUrl(locationInput.value);
                baseUrlInput.dispatchEvent(new Event('change', {bubbles: true}));
            });
        }
    };
});
