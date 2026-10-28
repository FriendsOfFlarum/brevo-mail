# Brevo Mail by FriendsOfFlarum

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/brevo-mail.svg)](https://packagist.org/packages/fof/brevo-mail) [![OpenCollective](https://img.shields.io/badge/opencollective-fof-blue.svg)](https://opencollective.com/fof/donate)

A [Flarum](https://flarum.org) extension. Send your forum's email through the [Brevo](https://www.brevo.com) (formerly Sendinblue) transactional email API.

### Setup

1. In Brevo, authenticate your sending domain (or verify the sender address you'll use) under **Senders, Domains & Dedicated IPs**.
2. Create an API key under **SMTP & API → API keys**. Use an API key (it starts with `xkeysib-`), not an SMTP key.
3. In your forum's admin panel, open **Email**, choose `brevo` as the driver, paste the API key, and save.
4. Make sure the **From** address matches a verified sender or authenticated domain, then use **Send Test Mail** to check everything works.

### Bounces and spam complaints

On Flarum 2.1 and later, Brevo can tell your forum when an address hard bounces, is invalid or blocked, or marks your mail as spam. Flarum then flags the member and can stop sending them notification emails. Soft bounces are ignored.

On the **Email** page, click **Register webhook with provider** under Bounce Handling. The extension adds a transactional webhook to your Brevo account, alongside any you already have. Brevo's requests are checked against Brevo's published IP ranges, which Flarum reads from the connection. If your forum is behind a proxy or CDN, your web server must pass the visitor's real IP address to PHP, for example with Apache's `mod_remoteip` or nginx's `real_ip` module. Flarum logs every webhook it refuses, so check the forum's log if bounces don't arrive.

### Already sending through Brevo over SMTP?

We recommend switching to the API. It sends each email in a single HTTPS request instead of an SMTP conversation, works on hosts that block outgoing SMTP ports, and reports failures with clear error messages.

Switching takes a minute: install this extension, create an API key as above, then on the **Email** page change the driver from `smtp` to `brevo` and paste in the key. Your sender settings in Brevo stay as they are.

### Installation

```sh
composer require fof/brevo-mail:"*"
```

### Updating

```sh
composer update fof/brevo-mail:"*"
php flarum cache:clear
```

### Links

[![OpenCollective](https://img.shields.io/badge/donate-friendsofflarum-44AEE5?style=for-the-badge&logo=open-collective)](https://opencollective.com/fof/donate)

- [Packagist](https://packagist.org/packages/fof/brevo-mail)
- [GitHub](https://github.com/FriendsOfFlarum/brevo-mail)
- [Discuss](https://discuss.flarum.org/d/40084)

An extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum).
