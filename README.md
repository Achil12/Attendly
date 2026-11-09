# Attendly — Your Personal Attendance

<p align="center">
  <img src="assets/images/logo.png" alt="Attendly Logo" width="180">
</p>

<p align="center">
  <strong>Smart Attendance Management System</strong>
  <br>
  A modern, secure, and flexible web-based employee attendance management platform.
  <br>
  Manage employees, monitor attendance, organize work schedules, and simplify workforce administration with Attendly.
  <br><br>

  <a href="https://github.com/Achil12/Attendly">
    <img src="https://img.shields.io/github/stars/Achil12/Attendly?style=flat-square&color=3979f6&label=Stars" alt="GitHub Stars">
  </a>
  <a href="https://github.com/Achil12/Attendly/network/members">
    <img src="https://img.shields.io/github/forks/Achil12/Attendly?style=flat-square&color=3979f6&label=Forks" alt="GitHub Forks">
  </a>
  <a href="https://github.com/Achil12/Attendly/issues">
    <img src="https://img.shields.io/github/issues/Achil12/Attendly?style=flat-square&color=3979f6&label=Issues" alt="GitHub Issues">
  </a>
  <a href="https://github.com/Achil12/Attendly/pulls">
    <img src="https://img.shields.io/github/issues-pr/Achil12/Attendly?style=flat-square&color=3979f6&label=Pull Requests" alt="Pull Requests">
  </a>
  <a href="https://github.com/Achil12/Attendly">
    <img src="https://img.shields.io/github/last-commit/Achil12/Attendly?style=flat-square&color=3979f6&label=Last Commit" alt="Last Commit">
  </a>
  <br>
  <a href="https://github.com/Achil12/Attendly">
    <img src="https://img.shields.io/badge/Platform-Web%20Application-3979f6?style=flat-square" alt="Platform">
  </a>
  <a href="https://www.php.net/">
    <img src="https://img.shields.io/badge/PHP-Backend-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  </a>
  <a href="https://www.mysql.com/">
    <img src="https://img.shields.io/badge/MySQL-Database-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL">
  </a>
  <a href="https://getbootstrap.com/">
    <img src="https://img.shields.io/badge/Bootstrap-5.3.3-7952B3?style=flat-square&logo=bootstrap&logoColor=white" alt="Bootstrap">
  </a>
</p>

<p align="center">
  <a href="#about">About</a> •
  <a href="#features">Features</a> •
  <a href="#technology-stack">Technology</a> •
  <a href="#getting-started">Installation</a> •
  <a href="#documentation">Documentation</a> •
  <a href="#contributing">Contributing</a>
</p>

---

## About

**Attendly** is a web-based employee attendance and workforce management system designed to simplify how organizations manage employee information, work schedules, attendance records, and administrative operations.

Built with a modern interface and a PHP-MySQL backend, Attendly provides an integrated platform for managing workforce activities through a centralized dashboard.

The system is designed with a multi-tenant architecture, allowing organizations to manage their own company data and users within an isolated environment.

Attendly also provides a foundation for hardware-based attendance recording through RFID technology using ESP32 devices.

Our goal is to make employee attendance management more accessible, organized, efficient, and scalable.

> **Attendly — Your Personal Attendance.**

## Why Attendly?

Traditional attendance management often relies on manual processes, spreadsheets, or disconnected systems.

These approaches can make it difficult to maintain accurate records, monitor employee attendance, and manage workforce information efficiently.

Attendly aims to address these challenges through:

- Centralized employee and attendance management.
- Organized employee scheduling and department management.
- Role-based access and administrative controls.
- Multi-company data separation.
- Flexible attendance methods.
- A foundation for RFID hardware integration.
- A responsive and user-friendly interface.

## Features

### 1. Dashboard

- Centralized administrative dashboard.
- Employee statistics and summaries.
- Attendance monitoring.
- Quick access to important information.
- Organized overview of workforce activities.

### 2. Employee Management

- Add, edit, and manage employee information.
- Employee identification using NIK.
- Employee profile management.
- Department and position assignments.
- Employee status management.
- RFID card assignment support.

### 3. Department Management

- Create and manage departments.
- Organize employees by department.
- Maintain structured organizational information.

### 4. Position Management

- Manage employee positions and job titles.
- Organize organizational roles.
- Associate positions with employee records.

### 5. Work Schedule Management

- Create and manage employee work schedules.
- Configure working days.
- Manage work hours.
- Organize employee attendance schedules.

### 6. Office Location Management

- Manage office locations.
- Configure attendance location information.
- Prepare location-based attendance management.

### 7. Attendance Management

- Manage employee attendance records.
- Monitor attendance activities.
- Record check-in and check-out information.
- Organize attendance history.
- Support attendance monitoring and reporting.

### 8. Leave and Permission Management

- Submit employee leave requests.
- Manage permission requests.
- Review request information.
- Organize approval workflows.

### 9. Reports

- Attendance reporting.
- Employee attendance summaries.
- Organized attendance history.
- Reporting and monitoring tools.

### 10. RFID Attendance Integration

Attendly is designed to support RFID-based attendance using ESP32 hardware.

Planned integration includes:

- RFID card identification.
- ESP32-based RFID reader communication.
- Employee-to-card association.
- Attendance recording through RFID.
- Communication between hardware and the Attendly server.

**Hardware integration status:** The application provides the foundation for RFID integration. Actual device operation requires compatible RFID reader hardware and ESP32 configuration.

### 11. Authentication and Security

- User authentication.
- Role-based access control.
- Session management.
- CSRF protection.
- Multi-tenant access separation.
- Company-level data isolation.
- Protected administrative operations.

### 12. Multi-Tenant Architecture

Attendly is designed to support multiple organizations within one application.

Each organization can manage its own:

- Employees.
- Departments.
- Positions.
- Work schedules.
- Office locations.
- Attendance records.
- User accounts.
- Administrative activities.

Company-level access restrictions help prevent users from accessing data belonging to other organizations.

---

## Technology Stack

Attendly uses common web technologies to provide a flexible and maintainable application.

| Component | Technology |
|---|---|
| Frontend | HTML5 |
| Styling | CSS3 |
| UI Framework | Bootstrap 5.3.3 |
| Icons | Font Awesome 6.7.2 |
| JavaScript | Vanilla JavaScript |
| Backend | PHP |
| Database | MySQL |
| Database Access | PDO |
| Web Server | Apache |
| Local Development | Laragon / XAMPP |
| Version Control | Git |
| Repository Hosting | GitHub |
| Hardware Integration | ESP32 + RFID (planned) |

## System Requirements

### Server Requirements

- PHP 8.1 or newer (PHP 8.2+ recommended).
- MySQL 8.0 or compatible MariaDB version.
- Apache Web Server.
- PDO MySQL extension.
- PHP JSON extension.
- PHP Session support.
- Modern web browser.

### Development Requirements

- Windows, Linux, or macOS.
- Laragon, XAMPP, or equivalent local server environment.
- Git.
- Visual Studio Code or another code editor.
- Web browser.

---

## Getting Started

Follow these instructions to install Attendly on your local development environment.

### 1. Clone the Repository

```bash
git clone https://github.com/Achil12/Attendly.git
```

Navigate to the project directory:

```bash
cd Attendly
```

### 2. Configure the Web Server

For Laragon:

Place the project inside:

```text
C:\laragon\www\Attendly
```

For XAMPP:

Place the project inside:

```text
C:\xampp\htdocs\Attendly
```

Start Apache and MySQL from your local server control panel.

### 3. Create the Database

Open phpMyAdmin:

```text
http://localhost/phpmyadmin
```

Create a new MySQL database for Attendly.

Use the database name configured in your project configuration.

Import the database schema or SQL installation file supplied with the project, if available.

### 4. Configure Database Connection

Open the database configuration file:

```text
config/db.php
```

Configure the database connection according to your local MySQL environment.

Example configuration:

```php
<?php

$host = 'localhost';
$dbname = 'attendly';
$username = 'root';
$password = '';

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$pdo = new PDO(
    $dsn,
    $username,
    $password,
    $options
);
```

**Note:** This is an illustrative PDO configuration. Keep the actual configuration and connection variable names used by your project's `config/db.php`.

### 5. Open Attendly

After configuring the database and starting Apache and MySQL, open:

```text
http://localhost/Attendly/
```

If your project uses a different entry point or virtual host, access it using the configured application URL.

---

## Application Architecture

Attendly uses a PHP-based web application architecture with a MySQL database.

The main components are:

```text
+---------------------------+
|       Web Browser         |
|  Administrator / Employee |
+-------------+-------------+
              |
              v
+---------------------------+
|       Frontend UI         |
| HTML / CSS / Bootstrap    |
| JavaScript                |
+-------------+-------------+
              |
              v
+---------------------------+
|       PHP Backend         |
| Authentication            |
| Authorization             |
| Business Logic            |
| Attendance Processing     |
+-------------+-------------+
              |
              v
+---------------------------+
|       MySQL Database      |
| Company Data              |
| Employee Data             |
| Attendance Records        |
| Work Schedules            |
+---------------------------+
```

### RFID Integration Architecture

The planned RFID attendance workflow:

```text
+---------------------------+
|       RFID Card           |
+-------------+-------------+
              |
              v
+---------------------------+
|     RFID Reader           |
+-------------+-------------+
              |
              v
+---------------------------+
|        ESP32              |
| Card Identification       |
+-------------+-------------+
              |
              v
+---------------------------+
|     Attendly Backend      |
| Card Validation           |
| Employee Identification   |
| Attendance Processing     |
+-------------+-------------+
              |
              v
+---------------------------+
|       MySQL Database      |
| Attendance Records        |
+---------------------------+
```

The RFID workflow requires compatible hardware, firmware, and server-side communication endpoints before it can operate.

---

## User Roles

Attendly supports role-based access to organize application permissions.

| Role | Description |
|---|---|
| Owner | Manages company-level settings and administration. |
| Admin | Manages operational data and administrative activities. |
| Manager | Handles assigned workforce and attendance management activities. |
| Employee | Uses employee-facing attendance and workforce features. |

Actual access permissions depend on the role configuration implemented in the application.

---

## Security

Security is an important part of Attendly's application architecture.

The system includes security mechanisms such as:

- Authentication and session handling.
- Role-based authorization.
- Multi-tenant company isolation.
- CSRF token protection.
- Server-side input validation.
- PDO prepared statements.
- Protected application routes and operations.
- Restricted access to company-specific resources.

For production deployment, always use HTTPS, strong passwords, secure database credentials, and regular backups.

---

## Project Status

Attendly is under active development.

| Module | Status |
|---|---|
| Authentication | Implemented |
| Registration | Implemented |
| Dashboard | Implemented |
| Employee Management | Implemented |
| Department Management | In Development |
| Position Management | In Development |
| Work Schedule Management | In Development |
| Office Location Management | In Development |
| Attendance Management | In Development |
| Leave Management | In Development |
| Reports | In Development |
| Multi-Tenant Architecture | Implemented |
| RFID Hardware Integration | Planned |

*Module status is indicative and should be updated as development progresses.*

---

## Roadmap

Future development plans include:

- [ ] Complete all employee management modules.
- [ ] Improve attendance monitoring.
- [ ] Complete work schedule management.
- [ ] Improve attendance reporting and exports.
- [ ] Complete leave approval workflows.
- [ ] Integrate ESP32 with RFID readers.
- [ ] Implement real-time RFID attendance recording.
- [ ] Improve dashboard analytics.
- [ ] Add notification capabilities.
- [ ] Improve mobile responsiveness.
- [ ] Strengthen production deployment and security.
- [ ] Expand system integrations.

---

## Screenshots

Application screenshots will be added here as the interface and modules are finalized.

Suggested documentation:

```text
docs/
└── screenshots/
    ├── login.png
    ├── dashboard.png
    ├── employees.png
    ├── attendance.png
    ├── schedules.png
    └── reports.png
```

---

## Contributing

Contributions, suggestions, and improvements are welcome.

To contribute:

1. Fork this repository.
2. Create a feature branch.

```bash
git checkout -b feature/your-feature-name
```

3. Make your changes.
4. Commit your changes.

```bash
git commit -m "Add: your feature description"
```

5. Push your branch.

```bash
git push origin feature/your-feature-name
```

6. Open a Pull Request.

Please ensure your changes are properly tested and documented.

## Community & Support

If you find Attendly useful, consider supporting the project.

- **GitHub Repository:** https://github.com/Achil12/Attendly
- **Issues:** https://github.com/Achil12/Attendly/issues
- **Pull Requests:** https://github.com/Achil12/Attendly/pulls
- **Discussions:** https://github.com/Achil12/Attendly/discussions

For bug reports, feature requests, or technical questions, please use GitHub Issues.

## Credits

Attendly is developed as a web-based employee attendance management project.

Special thanks to the open-source communities and developers behind the technologies used in this project.

## License

Please refer to the repository's `LICENSE` file for licensing information.

If no license has been added yet, the project remains subject to the default copyright rules.

---

<p align="center">
  <strong>Attendly — Your Personal Attendance</strong>
  <br>
  Making attendance management smarter, simpler, and more organized.
  <br><br>
  Made with ❤️ for better workforce management.
  <br><br>
  <a href="https://github.com/Achil12/Attendly">GitHub Repository</a>
</p>
