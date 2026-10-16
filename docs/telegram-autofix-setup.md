# Kan GitHub AutoFix → Telegram setup

This guide configures a dedicated Telegram bot to receive reports from the GitHub Actions workflow. The bot does not need a long-running process on the VPS for one-way alerts: GitHub Actions calls the official Telegram Bot API directly. A VPS is optional for a later command-capable bot.

## 1. Create a dedicated bot
1. Open Telegram and start a chat with the official @BotFather account.
2. Send /newbot, choose a display name (for example, Kan AutoFix Reports) and a unique username ending in bot.
3. Copy the token from BotFather. Treat it like a password; never commit it or paste it into an issue/chat.

## 2. Choose the destination chat
- Private chat: open the new bot and press Start (or send /start).
- Group: add the bot to the group and send a message there. Make sure group privacy/permissions allow the bot to be present; for ordinary sendMessage, it must be able to post.
- Channel: add the bot as an administrator with permission to post messages.

To obtain the numeric chat ID safely, use the official Bot API from a trusted terminal after sending a message to the bot/chat. Do not publish the token or the returned update JSON. Alternatively, use a trusted Telegram ID utility, understanding that it is a third party.

## 3. Add GitHub repository secrets
Open: https://github.com/kiankan/Kan-Developer-/settings/secrets/actions

Create these repository Actions secrets:
- OPENAI_API_KEY — OpenAI API key used for the diagnosis request.
- TELEGRAM_BOT_TOKEN — token from BotFather.
- TELEGRAM_CHAT_ID — destination chat ID (numeric; group IDs are often negative).

Never place these values in workflow YAML, source files, Issues, or commits.

## 4. Merge and enable the workflow
Review and merge PR #1: https://github.com/kiankan/Kan-Developer-/pull/1

After merging, check Actions → Kan GitHub AutoFix and confirm the workflow is enabled. The workflow sends Telegram alerts only when a monitored workflow fails and the secrets are configured.

## 5. Test safely
1. In Telegram, send /start to the dedicated bot (or post a message in the target group/channel).
2. Confirm all three secrets exist, with no leading/trailing spaces.
3. In GitHub, open Actions → Kan GitHub AutoFix → Run workflow. For the required run_id input, use the ID of a known failed run in this repository. This reprocesses that run and creates a report/Telegram alert if the workflow succeeds; it is not a synthetic message-only test.
4. Check the workflow run logs for “Telegram failure alert sent.” If the log says “Telegram alert skipped”, one or both Telegram secrets are missing. If Telegram rejects the message, verify the chat ID and that the bot was started/added and has posting permission.

The workflow deduplicates reports by run ID, so re-running the same run may not create a second issue/notification if an issue for that run already exists. For an end-to-end test, use a new failed run or intentionally fail a temporary test workflow/branch; do not break production code.

## Current safety boundary
The workflow diagnoses failures and sends reports only. It does not edit project files, apply AI-generated patches, or merge changes automatically. Review proposed fixes and test them before deployment.
