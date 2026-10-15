# Brevo Mail by FriendsOfFlarum

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/brevo-mail.svg)](https://packagist.org/packages/fof/brevo-mail) [![OpenCollective](https://img.shields.io/badge/opencollective-fof-blue.svg)](https://opencollective.com/fof/donate)

A [Flarum](https://flarum.org) extension. Send your forum's email through the [Brevo](https://www.brevo.com) (formerly Sendinblue) transactional email API.

### Setup

1. In Brevo, authenticate your sending domain (or verify the sender address you'll use) under **Senders, Domains & Dedicated IPs**.
2. Create an API key under **SMTP & API → API keys**. Use an API key (it starts with `xkeysib-`), not an SMTP key.
3. In your forum's admin panel, open **Email**, choose `brevo` as the driver, paste the API key, and save.
4. Make sure the **From** address matches a verified sender or authenticated domain, then use **Send Test Mail** to check everything works.

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
- [Discuss](https://discuss.flarum.org/d/PUT_DISCUSS_SLUG_HERE)

An extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum).
