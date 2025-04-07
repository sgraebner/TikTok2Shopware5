# TikTok Shop to Shopware 5 Order Sync

This is a plain PHP 8.3 application to synchronize orders from TikTok Shop to Shopware 5.

## Requirements
- PHP 8.3+
- SQLite (included via PDO)
- Supervisor (for background tasks)
- Access to TikTok Shop API and Shopware 5 REST API

## Setup
1. Clone the repository:
   ```bash
   git clone <repository-url> tiktok-shopware-sync
   cd tiktok-shopware-sync
   ```

2. Copy `.env.example` to `.env` and fill in your credentials:
   ```bash
   cp .env.example .env
   nano .env
   ```

3. Install SQLite database (created automatically on first run).

4. Configure the webhook endpoint:
   - Point your TikTok Shop webhook to `https://your-domain.com/public/webhook.php`.
   - Ensure the server can receive POST requests.

5. Set up Supervisor:
   - Edit `supervisord.conf` with the correct paths.
   - Copy to `/etc/supervisor/conf.d/tiktok-shopware-sync.conf`.
   - Reload Supervisor:
     ```bash
     supervisorctl reread
     supervisorctl update
     ```

## Usage
- **Webhook**: Automatically receives TikTok order IDs and queues them.
- **Worker**: Processes queued orders (`php cli.php worker`).
- **Monitor**: Checks order status hourly (`php cli.php monitor`).

Run manually via CLI:
```bash
php cli.php worker   # Process queued orders
php cli.php monitor  # Check open orders
```

## Logs
- Webhook logs: `webhook.log`
- Worker/Monitor logs: `app.log`
- Supervisor logs: `worker.{out,err}.log`, `monitor.{out,err}.log`

## Notes
- Rate limits are respected with sleeps and retries.
- Orders unresolved for 90+ days trigger an email to `it@kraeuterland.de`.
- Ensure API credentials have appropriate scopes.

## Contributing
Feel free to submit issues or pull requests on GitHub.

## License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.