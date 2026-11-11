# Virtualizor Extension for Paymenter

A Virtualizor server extension for [Paymenter](https://paymenter.org/) that allows you to automatically manage VPS services using the Virtualizor API.

This extension is **free to use**. I hope it helps other hosting providers and the Paymenter community!

## Features

### Admin Features
- Automatically create VPS after successful payment.
- Automatically suspend, unsuspend, terminate, and upgrade VPS.
- Save customer email, root password, and hostname.
- Allow customers to choose their hostname and operating system during checkout.
- Configure Plan ID, OS ID, server group, and hostname suffix for each product.

### Customer Features
- View VPS information (IP, hostname, status, and root password).
- Start, restart, stop, and force off VPS.
- Change root password.
- Reinstall operating system (Rebuild).

### Security
- Customers can only manage their own VPS services.
- Access permissions are handled through `routes.php`.

## Installation

1. Copy the `Virtualizor` folder into:

   `extensions/Servers/`

2. Enable the extension from **Admin > Extensions**.

3. Go to **Admin > Servers** and add a new server using the Virtualizor extension.

4. Enter your Virtualizor connection details:
   - Hostname / IP
   - Port (Default: 4085)
   - API Key
   - API Password

5. Click **Test Connection** to verify the connection.

6. Open your product settings and configure:
   - Virtualizor Plan ID
   - Operating System ID
   - Server Group
   - Hostname Suffix

That's it! Your Paymenter installation is ready to work with Virtualizor.

## Support This Project ❤️

I created this extension and decided to share it with the community for free.

If you find it useful, please consider supporting its development with a small donation:

**[Donate via NOWPayments](https://nowpayments.io/donation/virtualpaymenter)**

Every donation is appreciated and helps me maintain and improve this project.

You can also support us by choosing **TLFHost** for your hosting needs:

🌐 Website: https://tlfhost.com

Your support means a lot!

## License

Free to use. Contributions, suggestions, and feedback are welcome.
