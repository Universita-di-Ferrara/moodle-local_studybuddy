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
 * StudyBuddy chat and source interactions.
 *
 * @module     local_studybuddy/studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str', 'core/templates'],
        function(Ajax, Notification, Str, Templates) {
    var pollTimers = {};
    var uiStrings = {};
    var uiStringsPromise = null;

    /**
     * Load UI strings used by this AMD module.
     *
     * @return {Promise}
     */
    var loadUiStrings = function() {
        if (!uiStringsPromise) {
            uiStringsPromise = Str.get_strings([
                {key: 'sourcesused', component: 'local_studybuddy'},
                {key: 'welcomemessage', component: 'local_studybuddy'},
                {key: 'syncstatus:queued', component: 'local_studybuddy'},
                {key: 'syncstatus:running', component: 'local_studybuddy'},
                {key: 'syncstatus:failed', component: 'local_studybuddy'},
                {key: 'syncstatus:failedwitherror', component: 'local_studybuddy'},
                {key: 'syncstatus:ready', component: 'local_studybuddy'},
                {key: 'syncstatus:empty', component: 'local_studybuddy'},
                {key: 'syncstatus:providerchanged', component: 'local_studybuddy'},
                {key: 'syncstatus:locationchanged', component: 'local_studybuddy'},
                {key: 'syncstatus:locationinvalid', component: 'local_studybuddy'},
                {key: 'syncstatus:temporarilydisabled', component: 'local_studybuddy'},
                {key: 'chat:syncplaceholder', component: 'local_studybuddy'},
                {key: 'chat:responseavailable', component: 'local_studybuddy'},
                {key: 'chat:providerbusy', component: 'local_studybuddy'},
                {key: 'chat:providerunavailable', component: 'local_studybuddy'}
            ]).then(function(strings) {
                uiStrings.sourcesused = strings[0];
                uiStrings.welcomemessage = strings[1];
                uiStrings.syncqueued = strings[2];
                uiStrings.syncrunning = strings[3];
                uiStrings.syncfailed = strings[4];
                uiStrings.syncfailedwitherror = strings[5];
                uiStrings.syncready = strings[6];
                uiStrings.syncempty = strings[7];
                uiStrings.syncproviderchanged = strings[8];
                uiStrings.synclocationchanged = strings[9];
                uiStrings.synclocationinvalid = strings[10];
                uiStrings.syncdisabled = strings[11];
                uiStrings.chatsyncplaceholder = strings[12];
                uiStrings.chatresponseavailable = strings[13];
                uiStrings.chatproviderbusy = strings[14];
                uiStrings.chatproviderunavailable = strings[15];
                return uiStrings;
            });
        }

        return uiStringsPromise;
    };

    /**
     * Normalise Moodle js_call_amd arguments.
     *
     * @param {Object|Number} configOrCourseId Config object or course id.
     * @param {Number} chatId Optional chat id.
     * @return {Object}
     */
    var normaliseConfig = function(configOrCourseId, chatId) {
        if (configOrCourseId && typeof configOrCourseId === 'object') {
            return configOrCourseId;
        }

        return {
            courseid: Number(configOrCourseId || 0),
            chatid: Number(chatId || 0)
        };
    };

    /**
     * Build the context for a chat message template.
     *
     * @param {String} role Role.
     * @param {String} content Content.
     * @param {Array} sources Sources.
     * @param {Boolean} formatted Whether content is Moodle-generated safe HTML.
     * @return {Object} Template context.
     */
    var getMessageContext = function(role, content, sources, formatted) {
        var messageRole = role === 'user' ? 'user' : 'assistant';
        var messageSources = Array.isArray(sources) ? sources : [];
        return {
            role: messageRole,
            alignment: messageRole === 'user' ? 'align-self-end' : 'align-self-start',
            formatted: Boolean(formatted),
            content: String(content || ''),
            hassources: messageSources.length > 0,
            sourceslabel: uiStrings.sourcesused || '',
            sources: messageSources.map(function(source, index) {
                return {
                    label: String(source.title || source.filename || ''),
                    spaced: index > 0
                };
            })
        };
    };

    /**
     * Append rendered message contents to the message region.
     *
     * @param {HTMLElement} messagesBox Message region.
     * @param {Object} result Rendered template result.
     * @return {HTMLElement|null} Appended message node.
     */
    var appendRenderedMessage = function(messagesBox, result) {
        var nodes = Templates.appendNodeContents(messagesBox, result.html, result.js);
        var item = nodes && nodes.length ? nodes[0] : messagesBox.lastElementChild;

        if (!item) {
            return null;
        }

        messagesBox.scrollTop = messagesBox.scrollHeight;
        return item;
    };

    /**
     * Render one message.
     *
     * @param {HTMLElement} messagesBox Message region.
     * @param {String} role Role.
     * @param {String} content Content.
     * @param {Array} sources Sources.
     * @param {Boolean} formatted Whether content is Moodle-generated safe HTML.
     * @return {Promise} Template rendering promise.
     */
    var appendMessage = function(messagesBox, role, content, sources, formatted) {
        var context = getMessageContext(role, content, sources, formatted);

        return Templates.renderForPromise('local_studybuddy/chat_message', context).then(function(result) {
            return appendRenderedMessage(messagesBox, result);
        });
    };

    /**
     * Check whether an Ajax error is a sanitised provider failure.
     *
     * @param {Object} error Ajax error.
     * @return {Boolean}
     */
    var isProviderError = function(error) {
        return error && ['chat:providerbusy', 'chat:providerunavailable'].indexOf(error.errorcode) !== -1;
    };

    /**
     * Render a provider failure without exposing remote API diagnostics.
     *
     * @param {HTMLElement} messages Message region.
     * @param {Object} error Ajax error.
     * @param {HTMLElement} root Chat root.
     * @return {Promise} Template rendering promise.
     */
    var showProviderError = function(messages, error, root) {
        var message = error.errorcode === 'chat:providerbusy' ?
            uiStrings.chatproviderbusy : uiStrings.chatproviderunavailable;

        return appendMessage(messages, 'assistant', message, []).then(function() {
            announce(root, message);
            return message;
        });
    };

    /**
     * Toggle the assistant typing animation.
     *
     * @param {HTMLElement} root Root node.
     * @param {Boolean} visible Visible state.
     */
    var setTyping = function(root, visible) {
        var typing = root.querySelector('[data-region="typing"]');

        if (typing) {
            typing.classList.toggle('d-none', !visible);
        }
    };

    /**
     * Announces a chat update without making screen readers reread history.
     *
     * @param {HTMLElement} root Chat root node.
     * @param {String} message Status message.
     */
    var announce = function(root, message) {
        var region = root.querySelector('[data-region="chat-announcement"]');

        if (region) {
            region.textContent = message;
        }
    };

    /**
     * Enable or disable chat composer.
     *
     * @param {HTMLElement} root Root node.
     * @param {Boolean} disabled Disabled state.
     * @param {String} placeholder Optional placeholder.
     */
    var setChatAvailability = function(root, disabled, placeholder) {
        var input = root.querySelector('[data-region="chat-input"]');
        var submit = root.querySelector('[data-region="chat-submit"]');

        if (input) {
            input.disabled = disabled;
            if (placeholder !== undefined) {
                input.setAttribute('placeholder', placeholder || input.dataset.defaultPlaceholder || '');
            }
        }

        if (submit) {
            submit.disabled = disabled;
        }

        var form = root.querySelector('[data-region="chat-form"]');
        if (form) {
            form.setAttribute('aria-busy', disabled ? 'true' : 'false');
        }
    };

    /**
     * Keep the chat composer compact while allowing multiline questions.
     *
     * @param {HTMLElement} input Chat textarea.
     */
    var resizeChatInput = function(input) {
        var maxHeight = 192;

        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, maxHeight) + 'px';
        input.style.overflowY = input.scrollHeight > maxHeight ? 'auto' : 'hidden';
    };

    /**
     * Update spinner state on sync buttons.
     *
     * @param {HTMLElement} root Root node.
     * @param {Boolean} loading Loading state.
     */
    var setSyncButtonsLoading = function(root, loading) {
        root.querySelectorAll('[data-action="sync-course"]').forEach(function(button) {
            var icon = button.querySelector('i');

            button.disabled = loading;

            if (!icon) {
                return;
            }

            var defaultIcon = icon.dataset.defaultIcon || 'fa-sync-alt';

            icon.classList.remove('fa-sync-alt', 'fa-circle-notch', 'fa-spin');
            icon.classList.add(loading ? 'fa-circle-notch' : defaultIcon);

            if (loading) {
                icon.classList.add('fa-spin');
            }
        });
    };

    /**
     * Return whether sync is active.
     *
     * @param {String} status Status.
     * @return {Boolean}
     */
    var isActiveSync = function(status) {
        return ['queued', 'pending', 'running', 'syncing', 'in_progress'].indexOf(status) !== -1;
    };

    /**
     * Resolve the presentation for a known sync state.
     *
     * @param {String} state Sync state.
     * @param {Object} status Sync status.
     * @return {Object|null} Visible sync state, or null when unresolved.
     */
    var getSyncStatePresentation = function(state, status) {
        if (['queued', 'pending'].indexOf(state) !== -1) {
            return {type: 'info', text: uiStrings.syncqueued || '', show: true};
        }

        if (['running', 'syncing', 'in_progress'].indexOf(state) !== -1) {
            return {type: 'info', text: uiStrings.syncrunning || '', show: true};
        }

        if (state !== 'failed') {
            return null;
        }

        var failedText = status.lasterror ? (uiStrings.syncfailedwitherror || '').replace(
            '%%ERROR%%',
            status.lasterror
        ) : uiStrings.syncfailed || '';
        return {type: 'danger', text: failedText, show: true};
    };

    /**
     * Build the visible sync state for a page.
     *
     * @param {HTMLElement} root Root node.
     * @param {Object} status Sync status.
     * @param {Boolean} isChat Whether this is chat UI.
     * @return {Object} Visible sync state.
     */
    var getSyncPresentation = function(root, status, isChat) {
        var state = String(status.status || 'idle');
        var technicalstatus = !isChat || root.dataset.technicalStatus === '1';
        var statePresentation = getSyncStatePresentation(state, status);

        if (statePresentation) {
            return statePresentation;
        }

        if (status.needsreindex) {
            var reindexText = uiStrings.syncdisabled || '';
            if (technicalstatus && status.locationvalid === false) {
                reindexText = (uiStrings.synclocationinvalid || '')
                    .replace('%%LOCATION%%', status.configuredlocation || '');
            } else if (technicalstatus && status.locationchanged) {
                reindexText = (uiStrings.synclocationchanged || '')
                    .replace('%%LOCATION%%', status.configuredlocation || '')
                    .replace('%%STOREDLOCATION%%', status.storedlocation || '');
            } else if (technicalstatus) {
                reindexText = (uiStrings.syncproviderchanged || '').replace(
                    '%%PROVIDER%%',
                    status.providerlabel || status.provider || ''
                );
            }
            return {type: 'info', text: reindexText, show: true};
        }

        if (Number(status.available || 0) > 0) {
            var readyText = (uiStrings.syncready || '')
                .replace('%%ENABLED%%', status.enabled)
                .replace('%%READY%%', status.ready)
                .replace('%%TOTAL%%', status.total);
            return {type: 'success', text: readyText, show: !isChat};
        }

        return {
            type: 'warning',
            text: technicalstatus ? uiStrings.syncempty || '' : uiStrings.syncdisabled || '',
            show: true
        };
    };

    /**
     * Render current sync state.
     *
     * @param {HTMLElement} root Root node.
     * @param {Object} status Sync status.
     * @param {Boolean} isChat Whether this is chat UI.
     * @return {Boolean} Whether polling should continue.
     */
    var applySyncStatus = function(root, status, isChat) {
        var banner = root.querySelector('[data-region="studybuddy-status"]');
        var state = String(status.status || 'idle');
        var active = isActiveSync(state);
        var presentation = getSyncPresentation(root, status, isChat);

        if (banner) {
            banner.classList.remove('alert-info', 'alert-success', 'alert-warning', 'alert-danger', 'd-none');
            banner.classList.add('alert-' + presentation.type);
            banner.textContent = presentation.text;
            banner.classList.toggle('d-none', !presentation.show);
        }

        setSyncButtonsLoading(root, active);

        if (isChat) {
            setChatAvailability(
                root,
                active || status.needsreindex || Number(status.available || 0) === 0,
                active ? uiStrings.chatsyncplaceholder || '' : ''
            );
        }

        return active;
    };

    /**
     * Poll sync status.
     *
     * @param {Number} courseId Course id.
     * @param {HTMLElement} root Root node.
     * @param {Boolean} isChat Whether this is chat UI.
     */
    var pollSyncStatus = function(courseId, root, isChat) {
        var key = (isChat ? 'chat-' : 'sources-') + courseId;

        if (pollTimers[key]) {
            clearTimeout(pollTimers[key]);
            delete pollTimers[key];
        }

        Ajax.call([{
            methodname: 'local_studybuddy_get_sync_status',
            args: {courseid: courseId}
        }])[0].then(function(status) {
            if (applySyncStatus(root, status, isChat)) {
                pollTimers[key] = setTimeout(function() {
                    pollSyncStatus(courseId, root, isChat);
                }, 3000);
            }

            return status;
        }).catch(Notification.exception);
    };

    /**
     * Queue a sync and start polling.
     *
     * @param {Number} courseId Course id.
     * @param {HTMLElement} root Root node.
     * @param {Boolean} isChat Whether this is chat UI.
     */
    var queueSync = function(courseId, root, isChat) {
        setSyncButtonsLoading(root, true);

        Ajax.call([{
            methodname: 'local_studybuddy_sync_course',
            args: {courseid: courseId}
        }])[0].then(function() {
            pollSyncStatus(courseId, root, isChat);
            return true;
        }).catch(function(error) {
            setSyncButtonsLoading(root, false);
            Notification.exception(error);
        });
    };

    /**
     * Render the initial assistant welcome message.
     *
     * @param {HTMLElement} messages Message region.
     * @param {HTMLElement} root Root node.
     */
    var appendWelcomeMessage = function(messages, root) {
        return appendMessage(messages, 'assistant', root.dataset.welcome || uiStrings.welcomemessage || '', []);
    };

    /**
     * Return a value after a rendering promise completes.
     *
     * @param {*} value Value to return.
     * @return {Function} Promise continuation.
     */
    var returnValue = function(value) {
        return function() {
            return value;
        };
    };

    /**
     * Append a message and preserve a related response value.
     *
     * @param {Promise} rendering Message rendering promise.
     * @param {*} value Value to preserve.
     * @return {Promise} Promise resolving to the preserved value.
     */
    var renderWithValue = function(rendering, value) {
        return rendering.then(returnValue(value));
    };

    /**
     * Send one chat message through the external API.
     *
     * @param {Object} config Runtime config.
     * @param {String} text Message text.
     * @return {Promise} Message request.
     */
    var sendChatMessage = function(config, text) {
        return Ajax.call([{
            methodname: 'local_studybuddy_send_message',
            args: {
                chatid: config.chatid,
                message: text
            }
        }])[0];
    };

    /**
     * Append an assistant response and preserve the response data.
     *
     * @param {HTMLElement} messages Message region.
     * @param {Object} response Chat response.
     * @return {Promise} Promise resolving to the response data.
     */
    var appendChatResponse = function(messages, response) {
        return renderWithValue(appendMessage(messages, 'assistant', response.response, response.sources,
            response.responseformat === 1), response);
    };

    /**
     * Start a new temporary chat and reset its visible messages.
     *
     * @param {Object} config Runtime config.
     * @param {HTMLElement} root Root node.
     * @param {HTMLElement} messages Message region.
     * @return {Promise} Chat creation request.
     */
    var createNewChat = function(config, root, messages) {
        return Ajax.call([{
            methodname: 'local_studybuddy_create_chat',
            args: {courseid: config.courseid}
        }])[0].then(function(response) {
            config.chatid = Number(response.chatid || 0);
            messages.textContent = '';
            return renderWithValue(appendWelcomeMessage(messages, root), response);
        });
    };

    /**
     * Load temporary chat history.
     *
     * @param {Object} config Runtime config.
     * @param {HTMLElement} messages Message region.
     * @return {Promise} History request.
     */
    var loadChatHistory = function(config, messages) {
        var historyresponse = null;

        return Ajax.call([{
            methodname: 'local_studybuddy_get_history',
            args: {chatid: config.chatid}
        }])[0].then(function(response) {
            historyresponse = response;
            return Promise.all(response.messages.map(function(message) {
                return Templates.renderForPromise('local_studybuddy/chat_message', getMessageContext(
                    message.role,
                    message.content,
                    message.sources,
                    message.contentformat === 1
                ));
            }));
        }).then(function(results) {
            results.forEach(function(result) {
                appendRenderedMessage(messages, result);
            });

            return historyresponse;
        });
    };

    /**
     * Clear the visible history by starting a new active chat.
     *
     * @param {Object} config Runtime config.
     * @param {HTMLElement} root Root node.
     * @param {HTMLElement} messages Message region.
     */
    var clearHistory = function(config, root, messages) {
        Str.get_strings([
            {key: 'clearhistoryconfirm', component: 'local_studybuddy'},
            {key: 'clearhistoryconfirmbody', component: 'local_studybuddy'},
            {key: 'yes', component: 'moodle'},
            {key: 'no', component: 'moodle'}
        ]).then(function(strings) {
            Notification.confirm(strings[0], strings[1], strings[2], strings[3], function() {
                clearConfirmedHistory(config, root, messages);
            });

            return strings;
        }).catch(Notification.exception);
    };

    /**
     * Start a new chat after the user confirms history deletion.
     *
     * @param {Object} config Runtime config.
     * @param {HTMLElement} root Chat root.
     * @param {HTMLElement} messages Message region.
     * @return {void}
     */
    var clearConfirmedHistory = function(config, root, messages) {
        createNewChat(config, root, messages).catch(Notification.exception);
    };

    /**
     * Attach Moodle confirmation modals to forms.
     *
     * @param {HTMLElement} root Root node.
     */
    var attachConfirmForms = function(root) {
        root.querySelectorAll('form[data-confirm-submit]').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                if (form.dataset.confirmed === '1') {
                    return;
                }

                e.preventDefault();

                Str.get_strings([
                    {key: 'yes', component: 'moodle'},
                    {key: 'no', component: 'moodle'}
                ]).then(function(strings) {
                    Notification.confirm(
                        form.dataset.confirmTitle || '',
                        form.dataset.confirmMessage || '',
                        strings[0],
                        strings[1],
                        function() {
                            form.dataset.confirmed = '1';
                            form.submit();
                        }
                    );

                    return strings;
                }).catch(Notification.exception);
            });
        });

        root.querySelectorAll('button[data-confirm-submit]').forEach(function(button) {
            button.addEventListener('click', function(e) {
                var form = button.form;

                if (!form || button.dataset.confirmed === '1') {
                    return;
                }

                e.preventDefault();

                Str.get_strings([
                    {key: 'yes', component: 'moodle'},
                    {key: 'no', component: 'moodle'}
                ]).then(function(strings) {
                    Notification.confirm(
                        button.dataset.confirmTitle || '',
                        button.dataset.confirmMessage || '',
                        strings[0],
                        strings[1],
                        function() {
                            var hidden = document.createElement('input');

                            hidden.type = 'hidden';
                            hidden.name = button.name;
                            hidden.value = button.value;
                            form.appendChild(hidden);
                            button.dataset.confirmed = '1';
                            form.submit();
                        }
                    );

                    return strings;
                }).catch(Notification.exception);
            });
        });
    };

    /**
     * Initialise chat page.
     *
     * @param {Object|Number} configOrCourseId Config object or course id.
     * @param {Number} chatId Optional chat id.
     */
    var initChat = function(configOrCourseId, chatId) {
        var config = normaliseConfig(configOrCourseId, chatId);
        var root = document.querySelector('[data-region="studybuddy-chat"]');

        if (!root) {
            return;
        }

        var form = root.querySelector('[data-region="chat-form"]');
        var input = root.querySelector('[data-region="chat-input"]');
        var messages = root.querySelector('[data-region="messages"]');
        var clearButton = root.querySelector('[data-action="clear-history"]');

        if (input) {
            input.addEventListener('input', function() {
                resizeChatInput(input);
            });
            resizeChatInput(input);
        }

        loadUiStrings().then(function() {
            pollSyncStatus(config.courseid, root, true);
            return loadChatHistory(config, messages);
        }).catch(Notification.exception);

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            var text = input.value.trim();

            if (!text) {
                return;
            }

            input.value = '';
            resizeChatInput(input);
            setChatAvailability(root, true);
            appendMessage(messages, 'user', text, []).then(function() {
                setTyping(root, true);
                return sendChatMessage(config, text);
            }).then(function(response) {
                setTyping(root, false);
                return appendChatResponse(messages, response);
            }).then(function(response) {
                announce(root, uiStrings.chatresponseavailable || '');
                setChatAvailability(root, false);
                input.focus();
                return response;
            }).catch(function(error) {
                setTyping(root, false);
                setChatAvailability(root, false);
                if (isProviderError(error)) {
                    return showProviderError(messages, error, root);
                } else {
                    Notification.exception(error);
                    return null;
                }
            });
        });

        if (clearButton) {
            clearButton.addEventListener('click', function(e) {
                e.preventDefault();
                clearHistory(config, root, messages);
            });
        }
    };

    /**
     * Attach dynamic practice form options.
     *
     * @param {HTMLElement} root Practice page root.
     */
    var attachPracticeDynamicOptions = function(root) {
        var typeControls = root.querySelectorAll('[data-region="practice-activity-type"]');
        var options = root.querySelectorAll('[data-practice-option]');

        if (!typeControls.length || !options.length) {
            return;
        }

        var updateOptions = function() {
            var selectedControl = root.querySelector('[data-region="practice-activity-type"]:checked');
            var activityType = selectedControl ? selectedControl.value : 'quiz';

            options.forEach(function(option) {
                var optionName = option.dataset.practiceOption || '';
                var visible = optionName === 'nquestions' ? activityType === 'quiz' || activityType === 'flashcards' :
                    activityType === 'quiz' || activityType === 'flashcards';

                option.classList.toggle('d-none', !visible);
                option.querySelectorAll('input, select, textarea').forEach(function(field) {
                    field.disabled = !visible;
                });
            });
        };

        typeControls.forEach(function(typeControl) {
            typeControl.addEventListener('change', updateOptions);
        });
        updateOptions();
    };

    /**
     * Prevents duplicate submission while a regular page form is processing.
     *
     * @param {HTMLElement} root Page root.
     */
    var attachLoadingForms = function(root) {
        root.querySelectorAll('form[data-studybuddy-loading-form]').forEach(function(form) {
            form.addEventListener('submit', function() {
                var button = form.querySelector('.local-studybuddy-loading-button');

                if (!button || button.disabled) {
                    return;
                }

                button.disabled = true;
                button.classList.add('is-loading');
                button.setAttribute('aria-busy', 'true');

                var spinner = button.querySelector('.local-studybuddy-button-spinner');
                if (spinner) {
                    spinner.classList.remove('d-none');
                }

                var label = button.querySelector('.local-studybuddy-button-label');
                if (label && button.dataset.loadingText) {
                    label.textContent = button.dataset.loadingText;
                }
            });
        });
    };

    /**
     * Initialise practice page interactions.
     */
    var initPractice = function() {
        var root = document.querySelector('[data-region="studybuddy-practice"]');

        if (!root) {
            return;
        }

        attachConfirmForms(root);
        attachPracticeDynamicOptions(root);
        attachLoadingForms(root);
    };

    /**
     * Attach inline flashcard edit toggles.
     *
     * @param {HTMLElement} root Teacher page root.
     */
    var attachFlashcardEditToggles = function(root) {
        root.querySelectorAll('[data-action="edit-flashcard"]').forEach(function(button) {
            button.addEventListener('click', function() {
                var card = button.closest('[data-flashcard-index]');

                if (!card) {
                    return;
                }

                root.querySelectorAll('[data-flashcard-index]').forEach(function(otherCard) {
                    var isTarget = otherCard === card;
                    var panel = otherCard.querySelector('[data-region="flashcard-edit-panel"]');
                    var compactCitations = otherCard.querySelector('[data-region="flashcard-compact-citations"]');
                    var editButton = otherCard.querySelector('[data-action="edit-flashcard"]');

                    otherCard.classList.toggle('local-studybuddy-flashcard-review-card-editing', isTarget);

                    if (panel) {
                        panel.classList.toggle('d-none', !isTarget);
                    }

                    if (compactCitations) {
                        compactCitations.classList.toggle('d-none', isTarget);
                    }

                    if (editButton) {
                        editButton.setAttribute('aria-expanded', isTarget ? 'true' : 'false');
                    }
                });

                var firstField = card.querySelector('[data-region="flashcard-edit-panel"] textarea');
                if (firstField) {
                    firstField.focus();
                }
            });
        });

        var initialCard = root.querySelector('[data-autofocus="true"]');
        if (initialCard) {
            var initialField = initialCard.querySelector('[data-region="flashcard-edit-panel"] textarea');
            window.requestAnimationFrame(function() {
                initialCard.scrollIntoView({block: 'center'});
                if (initialField) {
                    initialField.focus({preventScroll: true});
                }
            });
        }
    };

    /**
     * Keep the correct-answer editor state in sync with the selected radio.
     *
     * @param {HTMLElement} root Teacher page root.
     */
    var attachCorrectAnswerControls = function(root) {
        var radios = root.querySelectorAll('.local-studybuddy-answer-correct-radio');

        if (!radios.length) {
            return;
        }

        var updateGroup = function(changedRadio) {
            radios.forEach(function(radio) {
                if (radio.name !== changedRadio.name) {
                    return;
                }

                var row = radio.closest('.local-studybuddy-answer-editor-row');
                var action = radio.closest('.local-studybuddy-answer-choice-control')
                    .querySelector('.local-studybuddy-answer-choice-action');
                var selected = radio.checked;

                if (row) {
                    row.classList.toggle('local-studybuddy-answer-editor-row-correct', selected);
                }

                if (action) {
                    action.textContent = selected ? action.dataset.correctLabel : action.dataset.markLabel;
                    action.classList.toggle('badge', selected);
                    action.classList.toggle('badge-primary', selected);
                    action.classList.toggle('rounded-pill', selected);
                    action.classList.toggle('text-muted', !selected);
                }
            });
        };

        radios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                updateGroup(radio);
            });

            if (radio.checked) {
                updateGroup(radio);
            }
        });
    };

    /**
     * Keep the teacher item count in sync with the selected activity type.
     *
     * @param {HTMLElement} root Teacher page root.
     */
    var attachTeacherActivityOptions = function(root) {
        var typeControls = root.querySelectorAll('[data-studybuddy-activity-type]');
        var itemCount = root.querySelector('#id_teacher_nquestions');

        if (!typeControls.length || !itemCount) {
            return;
        }

        var updateMinimum = function() {
            var selectedControl = root.querySelector('[data-studybuddy-activity-type]:checked');
            var activityType = selectedControl ? selectedControl.value : 'quiz';
            var minimum = activityType === 'quiz' ?
                Number(itemCount.dataset.minQuiz || 4) :
                Number(itemCount.dataset.minFlashcards || 1);

            itemCount.min = String(minimum);
            if (Number(itemCount.value) < minimum) {
                itemCount.value = String(minimum);
            }
        };

        typeControls.forEach(function(typeControl) {
            typeControl.addEventListener('change', updateMinimum);
        });
        updateMinimum();
    };

    /**
     * Initialise teacher page interactions.
     */
    var initTeacher = function() {
        var root = document.querySelector('[data-region="studybuddy-teacher"]');

        if (!root) {
            return;
        }

        attachConfirmForms(root);
        attachFlashcardEditToggles(root);
        attachCorrectAnswerControls(root);
        attachTeacherActivityOptions(root);
        attachLoadingForms(root);
    };

    /**
     * Initialise source toggles.
     *
     * @param {Object|Number} configOrCourseId Config object or course id.
     */
    var initSources = function(configOrCourseId) {
        var config = normaliseConfig(configOrCourseId);
        var root = document.querySelector('[data-region="studybuddy-sources"]');

        if (!root) {
            return;
        }

        loadUiStrings().then(function() {
            pollSyncStatus(config.courseid, root, false);
            return true;
        }).catch(Notification.exception);

        var form = root.querySelector('[data-action="sync-course-form"]');

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                queueSync(config.courseid, root, false);
            });
        }

        root.querySelectorAll('[data-action="toggle-source"]').forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var row = toggle.closest('[data-documentid]');

                toggle.disabled = true;

                Ajax.call([{
                    methodname: 'local_studybuddy_toggle_source',
                    args: {
                        courseid: config.courseid,
                        documentid: Number(row.dataset.documentid),
                        enabled: toggle.checked
                    }
                }])[0].then(function() {
                    toggle.disabled = false;
                    return true;
                }).catch(function(error) {
                    toggle.checked = !toggle.checked;
                    toggle.disabled = false;
                    Notification.exception(error);
                });
            });
        });
    };

    return {
        initChat: initChat,
        initPractice: initPractice,
        initTeacher: initTeacher,
        initSources: initSources
    };
});
