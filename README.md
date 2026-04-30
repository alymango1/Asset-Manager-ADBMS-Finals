# Asset Manager — Batangas State University

> **ADBMS Finals Project** · JPLPC Malvar Campus

A web-based equipment asset management system built for Batangas State University. It allows administrators to manage the university's equipment inventory and faculty members to browse and reserve equipment for academic use.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [User Roles](#user-roles)
- [Pages & Modules](#pages--modules)
- [Database](#database)
- [Screenshots](#screenshots)
- [Contributors](#contributors)

---

## Overview

The **Asset Manager** is a role-based web application with two portals:

- **Admin Portal** — Full system control for managing equipment, users, and reservations.
- **Faculty Portal** — Faculty members can browse available equipment and submit reservation requests.

---

## Features

### Admin
- Dashboard with live statistics (total equipment, in-use, available, under maintenance, reservations today)
- Equipment inventory management (add, edit, delete, status updates)
- User account management (create, edit, delete users)
- Reservation approval/rejection workflow
- In-use equipment tracking and return processing
- Full transaction history and audit log

### Faculty
- Dashboard overview of equipment availability
- Browse and search the equipment catalog
- Submit reservation requests with date selection
- Track reservation status (pending / approved / rejected / cancelled / returned)
- Cancel pending reservations

### System-wide
- Separate login portals for Admin and Faculty
- Session-based authentication with role enforcement
- CSRF token protection on all forms
- Pagination, search, and filter on all data tables
- Responsive design (mobile-friendly)

---

## Tech Stack

| Layer      | Technology                          |
|------------|-------------------------------------|
| Frontend   | HTML5, CSS3 (custom, no framework)  |
| Backend    | PHP (procedural)                    |
| Database   | MySQL via MySQLi                    |
| Fonts      | Google Fonts (DM Sans, DM Mono, Cormorant Garamond, DM Serif Display) |
| Server     | Apache / XAMPP (localhost)          |

---

## Project Structure

```
Asset-Manager-ADBMS-Finals/
│
├── index.php                  # Landing page (portal selector)
│
├── admin/                     # Admin portal
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── equipments.php
│   ├── add_equipment.php
│   ├── edit_equipment.php
│   ├── delete_equipment.php
│   ├── update_equipment_status.php
│   ├── users.php
│   ├── add_user.php
│   ├── edit_user.php
│   ├── reservation.php
│   ├── approve.php
│   ├── reject.php
│   ├── in_use.php
│   ├── return_equipment.php
│   ├── transactions.php
│   └── sidebar.php
│
├── faculty/                   # Faculty portal
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── reservation.php
│   ├── reserve_item.php
│   ├── my_reservations.php
│   ├── cancel_reservation.php
│   └── sidebar.php
│
├── config/
│   └── delete_user.php
│
├── database/
│   └── db.php                 # MySQL connection config
│
├── css/
│   ├── style.css
│   ├── admin/                 # Admin-specific stylesheets
│   └── faculty/               # Faculty-specific stylesheets
│
└── img/
    ├── bsu.png                # BSU logo
    ├── campus.jpg / .png      # Campus background images
    └── favicon-96.png
```

---

## Getting Started

### Prerequisites

- [XAMPP](https://www.apachefriends.org/) (or any PHP + MySQL stack)
- PHP 7.4+
- MySQL 5.7+

### Installation

1. **Clone or extract** the project into your web server's root directory:
   ```
   /xampp/htdocs/Asset-Manager-ADBMS-Finals/
   ```

2. **Create the database:**
   - Open [phpMyAdmin](http://localhost/phpmyadmin)
   - Create a new database named `asset_manager`
   - Import the SQL schema (if provided separately)

3. **Configure the database connection** in `database/db.php`:
   ```php
   $servername = 'localhost';
   $username   = 'root';
   $password   = '';          // your MySQL password
   $dbname     = 'asset_manager';
   ```
   > The connection automatically tries port `3306`, then falls back to `3307`.

4. **Start Apache and MySQL** in XAMPP, then visit:
   ```
   http://localhost/Asset-Manager-ADBMS-Finals/
   ```

---

## User Roles

| Role    | Access                                                              |
|---------|---------------------------------------------------------------------|
| `admin` | Full access — equipment, users, reservations, transactions          |
| `staff` | Admin-level access (same portal, assigned during user creation)     |
| Faculty | Faculty portal only — browse equipment and manage own reservations  |

> Faculty accounts are separate from admin/staff accounts and log in through a different portal.

---

## Pages & Modules

### Landing Page (`index.php`)
Dual-portal selector styled with the BSU crimson-and-gold theme. Users choose **Admin Portal** or **Faculty Portal** to proceed to their respective login pages.

---

### Admin Portal

| Page | Description |
|------|-------------|
| `login.php` | Admin authentication |
| `dashboard.php` | Stats overview — equipment counts, pending reservations, recent reservation table, quick user creation |
| `equipments.php` | Paginated equipment inventory with search, category, and status filters |
| `add_equipment.php` | Form to add new equipment records |
| `edit_equipment.php` | Edit existing equipment details |
| `delete_equipment.php` | Delete an equipment record |
| `update_equipment_status.php` | Change equipment status (Available / In-Use / Under Maintenance) |
| `users.php` | User management table with search and pagination |
| `add_user.php` | Create a new admin/staff user |
| `edit_user.php` | Edit user profile and credentials |
| `reservation.php` | View and filter all reservations; approve or reject pending requests |
| `approve.php` | Approve a reservation and mark equipment as In-Use |
| `reject.php` | Reject a reservation request |
| `in_use.php` | View all currently in-use equipment; process equipment returns |
| `return_equipment.php` | Mark equipment as returned and update status |
| `transactions.php` | Full audit trail of all reservation transactions |

---

### Faculty Portal

| Page | Description |
|------|-------------|
| `login.php` | Faculty authentication |
| `dashboard.php` | Equipment availability overview |
| `reservation.php` | Browse equipment catalog and submit new reservation requests |
| `reserve_item.php` | Backend handler for submitting a reservation |
| `my_reservations.php` | Track all personal reservations and their current status |
| `cancel_reservation.php` | Cancel a pending reservation |

---

## Database

**Database name:** `asset_manager`

Core tables inferred from the application:

| Table | Purpose |
|-------|---------|
| `users` | Stores admin/staff accounts (`user_id`, `full_name`, `username`, `password`, `roles`) |
| `equipments` | Equipment records (`equipment_id`, `resource_name`, `categories`, `status`, …) |
| `reservations` | Reservation requests (`reservation_id`, `equipment_id`, `requested_by`, `reserved_date`, `status`) |

Equipment statuses: `Available`, `In-Use`, `Under Maintenance`

Reservation statuses: `pending`, `approved`, `rejected`, `cancelled`, `returned`

---

## Screenshots

> _Add screenshots here after running the application locally._
>
> Suggested screenshots to include:
> - Landing page (portal selector)
> - Admin login
> - Admin dashboard
> - Equipment inventory table
> - Reservation management page
> - Faculty login
> - Faculty equipment catalog / reservation form
> - My Reservations (faculty view)

---

## Contributors

| Name | Role |
|------|------|
| _(add your names here)_ | Developer |

> Built as a Finals project for **Advanced Database Management Systems (ADBMS)** · Batangas State University — JPLPC Malvar Campus · 2026

---

## License

This project was created for academic purposes. All rights reserved by the authors.
