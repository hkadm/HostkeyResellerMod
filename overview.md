# HostkeyResellerMod Architecture Overview

## Introduction

HostkeyResellerMod is a module designed to integrate with WHMCS (Web Host Manager Complete Solution) to facilitate the reselling of HOSTKEY servers, which include both VPS (Virtual Private Servers) and dedicated servers. This module is tailored for version 8.8.0 of WHMCS and leverages various hooks and server modules to provide a seamless experience for setting up and managing the reselling services directly from the WHMCS platform.

## High-Level Architecture

The HostkeyResellerMod consists of several components that interact with each other to provide a range of functionalities from server provisioning to client management. The system architecture is modular, comprising PHP scripts that interface with the WHMCS system, an external API, and a frontend client area.

### Key Components

1. **WHMCS Hooks (`includes/hooks/hostkeyresellermod.php`)**:
   - These are PHP functions that are triggered by specific events in WHMCS, such as invoice payments or service cancellations. They handle business logic related to these events, like activating a server on payment or handling cancellation requests.

2. **Server Module (`modules/servers/hostkeyresellermod/hostkeyresellermod.php`)**:
   - This module is responsible for the direct interaction with the servers being resold. It includes functions for creating accounts, handling client area requests, and managing server data. It acts as a bridge between WHMCS and the servers, facilitating operations like account provisioning and status updates.

3. **Addon Module (`modules/addons/hostkeyresellermod/hostkeyresellermod.php`)**:
   - Provides additional administrative functionalities within the WHMCS admin panel. It handles configuration settings, activation/deactivation of the module, and includes a mechanism to load and clear data related to the reselling operations.

4. **Cron Script (`modules/addons/hostkeyresellermod/cron.php`)**:
   - A scheduled task that executes operations which need to be run periodically, such as updating or syncing data between WHMCS and the servers.

5. **Client and Error Templates (`modules/servers/hostkeyresellermod/clientarea.tpl` and `modules/servers/hostkeyresellermod/error.tpl`)**:
   - These are presentation files that define the layout and structure of the user interface presented to the end-users in their client area.

6. **Database Interactions**:
   - The module interacts with the WHMCS database using the `WHMCS\Database\Capsule` manager, which allows for database operations using an object-relational mapping approach.

7. **External API Calls**:
   - The module communicates with external APIs provided by HOSTKEY for operations such as retrieving server information, managing API keys, and other server-related tasks.

### Architectural Invariants

- **Separation of Concerns**: The system architecture distinctly separates logic related to server management, event handling via hooks, and user interface management.
- **Dependency on WHMCS**: The module operates under the assumption that it is deployed within a WHMCS installation of version 8.8.0 or compatible versions.
- **State Management**: Server state is managed externally by the HOSTKEY API and synced with WHMCS via the module.

### Boundaries and Interfaces

- **WHMCS System Boundary**: The module interacts with WHMCS through defined hooks and APIs, adhering to the expected parameters and structures provided by WHMCS.
- **External API Boundary**: Interaction with the HOSTKEY API is encapsulated within specific functions that handle all HTTP requests and responses, abstracting these details from the rest of the module.

## Conclusion

The HostkeyResellerMod module is a comprehensive solution for integrating HOSTKEY server reselling into WHMCS. It leverages WHMCS's extensible architecture to provide a feature-rich, seamless experience for both administrators and end-users. The modular design ensures that each component can be maintained and updated independently, providing robustness and scalability to the system.