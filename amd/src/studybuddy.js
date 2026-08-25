/**
 * StudyBuddy chat and source interactions.
 *
 * @module     local_studybuddy/studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {
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
                {key: 'syncstatus:temporarilydisabled', component: 'local_studybuddy'},
                {key: 'chat:syncplaceholder', component: 'local_studybuddy'},
                {key: 'chat:responseavailable', component: 'local_studybuddy'}
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
                uiStrings.syncdisabled = strings[9];
                uiStrings.chatsyncplaceholder = strings[10];
                uiStrings.chatresponseavailable = strings[11];
                return uiStrings;
            });
        }

        return uiStringsPromise;
    };

    /**
     * Escape HTML for local rendering.
     *
     * @param {String} text Raw text.
     * @return {String}
     */
    var escapeHtml = function(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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
     * Render one message.
     *
     * @param {HTMLElement} messagesBox Message region.
     * @param {String} role Role.
     * @param {String} content Content.
     * @param {Array} sources Sources.
     * @param {Boolean} formatted Whether content is Moodle-generated safe HTML.
     */
    var appendMessage = function(messagesBox, role, content, sources, formatted) {
        var item = document.createElement('div');
        var alignment = role === 'user' ? 'align-self-end' : 'align-self-start';

        item.className = 'local-studybuddy-message local-studybuddy-message-' + role +
            ' entry-animation d-flex mb-3 ' + alignment;

        var html = '<div class="local-studybuddy-message-content">' +
            '<div class="d-inline-block mw-100 p-3 border-0 rounded shadow-sm local-studybuddy-message-text-bubble">' +
            '<div class="local-studybuddy-message-text text-break">' +
            (formatted ? String(content || '') : escapeHtml(content).replace(/\n/g, '<br>')) +
            '</div>';

        if (sources && sources.length) {
            html += '<div class="mt-2 p-3 border rounded bg-white local-studybuddy-message-sources">' +
                '<div class="local-studybuddy-message-sources-title">' + escapeHtml(uiStrings.sourcesused || '') + '</div>';

            html += '<ul class="mt-1 pl-4 local-studybuddy-message-source-list">';

            sources.forEach(function(source, index) {
                html += '<li class="local-studybuddy-message-source-item' + (index ? ' mt-2' : '') + '">' +
                    '<div class="local-studybuddy-message-source-name small">' +
                    escapeHtml(source.title || source.filename || '') +
                    '</div></li>';
            });

            html += '</ul></div>';
        }

        html += '</div></div></div>';

        item.innerHTML = html;
        item.querySelectorAll('.local-studybuddy-message-text pre').forEach(function(element) {
            element.classList.add('my-2', 'p-3', 'rounded', 'bg-dark', 'text-light');
        });
        item.querySelectorAll('.local-studybuddy-message-text code').forEach(function(element) {
            if (!element.closest('pre')) {
                element.classList.add('px-1', 'rounded', 'bg-light', 'text-dark');
            }
        });
        item.querySelectorAll('.local-studybuddy-message-text ul, .local-studybuddy-message-text ol').forEach(function(element) {
            element.classList.add('my-2', 'pl-4');
        });
        item.querySelectorAll('.local-studybuddy-message-text li + li').forEach(function(element) {
            element.classList.add('mt-1');
        });
        var paragraphs = item.querySelectorAll('.local-studybuddy-message-text p');
        paragraphs.forEach(function(element, index) {
            element.classList.add('mb-3');
            if (index === paragraphs.length - 1) {
                element.classList.add('mb-0');
            }
        });
        item.querySelectorAll('.local-studybuddy-message-text h1, .local-studybuddy-message-text h2, ' +
            '.local-studybuddy-message-text h3, .local-studybuddy-message-text h4, ' +
            '.local-studybuddy-message-text h5, .local-studybuddy-message-text h6').forEach(function(element) {
            element.classList.add('mt-3', 'mb-2');
        });
        messagesBox.appendChild(item);
        messagesBox.scrollTop = messagesBox.scrollHeight;
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
        var type = 'info';
        var text = '';
        var active = isActiveSync(state);
        var showBanner = !isChat;
        var technicalstatus = !isChat || root.dataset.technicalStatus === '1';

        if (state === 'queued' || state === 'pending') {
            text = uiStrings.syncqueued || '';
            showBanner = true;
        } else if (state === 'running' || state === 'syncing' || state === 'in_progress') {
            text = uiStrings.syncrunning || '';
            showBanner = true;
        } else if (state === 'failed') {
            type = 'danger';
            text = status.lasterror ? (uiStrings.syncfailedwitherror || '').replace('%%ERROR%%', status.lasterror) :
                uiStrings.syncfailed || '';
            showBanner = true;
        } else if (status.needsreindex) {
            text = technicalstatus ? (uiStrings.syncproviderchanged || '').replace(
                    '%%PROVIDER%%',
                    status.providerlabel || status.provider || ''
                ) : uiStrings.syncdisabled || '';
            showBanner = true;
        } else if (Number(status.available || 0) > 0) {
            type = 'success';
            text = (uiStrings.syncready || '')
                .replace('%%ENABLED%%', status.enabled)
                .replace('%%READY%%', status.ready)
                .replace('%%TOTAL%%', status.total);
        } else {
            type = 'warning';
            text = technicalstatus ? uiStrings.syncempty || '' : uiStrings.syncdisabled || '';
            showBanner = true;
        }

        if (banner) {
            banner.classList.remove('alert-info', 'alert-success', 'alert-warning', 'alert-danger', 'd-none');
            banner.classList.add('alert-' + type);
            banner.textContent = text;
            banner.classList.toggle('d-none', !showBanner);
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
        appendMessage(messages, 'assistant', root.dataset.welcome || uiStrings.welcomemessage || '', []);
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
                Ajax.call([{
                    methodname: 'local_studybuddy_create_chat',
                    args: {courseid: config.courseid}
                }])[0].then(function(response) {
                    config.chatid = Number(response.chatid || 0);
                    messages.innerHTML = '';
                    appendWelcomeMessage(messages, root);
                    return response;
                }).catch(Notification.exception);
            });

            return strings;
        }).catch(Notification.exception);
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

            return Ajax.call([{
                methodname: 'local_studybuddy_get_history',
                args: {chatid: config.chatid}
            }])[0].then(function(response) {
                response.messages.forEach(function(message) {
                    appendMessage(messages, message.role, message.content, message.sources, message.contentformat === 1);
                });

                return response;
            });
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
            appendMessage(messages, 'user', text, []);
            setTyping(root, true);

            Ajax.call([{
                methodname: 'local_studybuddy_send_message',
                args: {
                    chatid: config.chatid,
                    message: text
                }
            }])[0].then(function(response) {
                setTyping(root, false);
                appendMessage(messages, 'assistant', response.response, response.sources, response.responseformat === 1);
                announce(root, uiStrings.chatresponseavailable || '');
                setChatAvailability(root, false);
                input.focus();

                return response;
            }).catch(function(error) {
                setTyping(root, false);
                setChatAvailability(root, false);
                Notification.exception(error);
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
