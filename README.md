# StudyBuddy (`local_studybuddy`)

StudyBuddy is a Moodle local plugin for AI-assisted study workflows grounded in course materials. It combines RAG chat, source indexing, temporary student self-assessment quizzes, and teacher-reviewed quiz drafts that can be published as native Moodle quiz activities.

## Features

- Cloud RAG ingestion for visible course activities and files.
- Course-source chat for the current Moodle session, with source references.
- Student practice quizzes stored temporarily in plugin tables, not as official course activities.
- Teacher quiz draft generation, review, duplicate/delete, and publication to Moodle quiz/question bank.
- Source management with per-document enable/disable controls.
- Cloud provider selection for OpenAI GPT, Google Gemini and Vertex AI.
- The plugin stores Moodle source metadata and remote-file mappings only. Parsing,
  chunking, embeddings and retrieval are delegated to the selected cloud provider.
- Moodle privacy API coverage for practice, drafts, chat, messages, and usage audit records.

## Installation

1. Copy the plugin to `local/studybuddy`.
2. Visit Moodle site administration to complete the database upgrade.
3. Configure `Site administration > Plugins > Local plugins > StudyBuddy`.
4. Select OpenAI GPT, Google Gemini or Vertex AI and complete only the settings shown for that provider.
   There is no local/mock provider or local embedding fallback.
5. Open a course, use `StudyBuddy sources` to index course content, then use the chat, student practice, or teacher studio pages.

The administrator prompt settings are in the `AI prompts` section. The chat instructions,
activity instructions, provider input format, and activity JSON schema are all editable there;
the JSON schema must remain valid JSON. Provider credentials are stored as Moodle admin settings
and should be protected using the site configuration and secret management policy.

## Provider implementation layout

Provider code is organised by cloud service so that each integration can be located without
searching the shared application services:

```text
classes/local/
  provider/
    ai_provider.php
    chat_provider_interface.php
    provider_factory.php
    provider_file_scope.php
    openai/
      openai_client.php
      openai_*_api.php
      openai_*_provider.php
      openai_course_vector_store_service.php
    google/
      google_client.php
      google_*_api.php
      google_*_provider.php
      google_course_file_search_store_service.php
    vertexai/
      vertexai_client.php
      vertexai_rag_api.php
      vertexai_*_provider.php
      vertexai_course_rag_service.php
```

The classes directly under `classes/local` contain provider-independent Moodle workflows,
such as chat orchestration, source catalogue management, asynchronous generation and
publishing. The classes under `classes/local/provider/<provider>` contain only the client,
API wrappers and RAG implementation for that cloud provider.

## Corpus Sync

Course corpus synchronisation is queued as a Moodle adhoc task. The UI returns immediately, shows the same pending/syncing style used by the AI Chat block, and polls `local_studybuddy_get_sync_status` until the selected provider reports ready sources. Make sure Moodle cron is running so queued jobs in `local_studybuddy_syncjob` are processed.

## Supported Sources

StudyBuddy discovers direct Moodle content from Page, Book, Label and Lesson, and scans common file areas for supported files. The default extension list is `pdf,docx,pptx,txt,html,htm,md`. Native Moodle content is prepared as temporary upload text; supported files are uploaded in their original format. The selected cloud provider performs parsing, chunking and embeddings.

For OpenAI, Google, and Vertex AI, chat and generation use the selected provider's native retrieval service. Only catalogued sources that are enabled and visible to the current user are passed to the provider; Google uses document metadata filters, OpenAI uses file-search filters, and Vertex AI uses RAG file IDs.

## Teacher Workflow

1. Index course content from `StudyBuddy sources` or the teacher studio.
2. Generate a quiz draft from a prompt, number of questions, and difficulty.
3. Review generated questions, answers, feedback, and citations.
4. Save the reviewed draft.
5. Publish explicitly as a native Moodle quiz. StudyBuddy creates the quiz module and multichoice questions in the course question bank only after this confirmation.

Teacher drafts can also be generated as H5P Dialog Cards when the H5P Dialog Cards library is installed and enabled. H5P publication creates a normal Moodle H5P activity after review.

## Student Workflow

Students can chat with course sources and generate temporary practice quizzes. Chat messages and conversation context are held only in the current Moodle session and are not written to the database. To keep the Moodle session and provider request bounded, only the most recent message pairs are retained; administrators can adjust this limit through `Maximum temporary chat messages`. Practice quizzes are private plugin records, expire according to `practiceretention`, and do not create official course activities or gradebook items.

## Privacy

Temporary practice data and teacher drafts are stored in Moodle plugin tables. The local source catalogue contains metadata and enable/disable state, not a local RAG index. Chat conversations remain only in the current Moodle session. Course content, prompts and generated requests may be sent to the selected remote provider according to site configuration, provider terms and applicable data-protection requirements.

## Backup And Restore

StudyBuddy v1 does not define a custom activity module and does not add plugin-specific backup steps. Published quizzes are native Moodle quiz activities and follow Moodle quiz backup/restore behavior.
