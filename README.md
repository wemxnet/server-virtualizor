# Virtualizor

Sell virtual servers from a [Virtualizor](https://www.virtualizor.com) master on WemX. Each order creates a server from a Virtualizor plan, owned by the customer's Virtualizor account, and the customer manages it from the order page.

## Features

- Create servers from a Virtualizor plan (KVM, OpenVZ, Xen, LXC and more)
- Checkout asks for the hostname and operating system
- Reuse the customer's Virtualizor account by email, or create one
- Email the customer the hostname, IP, root password and panel login (editable under Email Templates)
- One-click end-user panel login for the customer, and open-as-user for staff
- Start, stop and restart, plus root password changes, from the order page
- Suspend, unsuspend, terminate, and plan changes on upgrade or downgrade

## Install

Install from the WemX marketplace, or download `Virtualizor.zip` from a GitHub release and place the `Virtualizor` folder at `extensions/Servers/Virtualizor`. Then enable **Virtualizor**.

Publishing a release builds `Virtualizor.zip`. Unzipping it creates a folder named `Virtualizor`. GitHub's own "Source code" archive still unpacks to `server-virtualizor-<tag>`. Use `Virtualizor.zip`.

## Connection

Use the master's hostname without a port, for example `https://virt.example.com`, the admin port (`4085`) and the end-user port (`4083`). Create an API key and password under **Configuration → API Credentials**. Virtualizor ships with a self-signed certificate, so SSL verification is off by default.

## Packages

Pick the Virtualizor plan that sets RAM, disk, CPU, bandwidth and IPs, and a default OS template. Panel login, power controls and root password changes can be turned off per package.

## Credits

Based on the MIT-licensed Virtualizor extension from [Paymenter](https://github.com/Paymenter/Paymenter).
