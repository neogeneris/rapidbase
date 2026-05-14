# RapidBase API Documentation

## Overview
RapidBase provides a dynamic, endpoint-driven API architecture that allows developers to create REST-like endpoints with minimal boilerplate. The system includes:

- **Dynamic Router**: Automatically maps requests to PHP classes based on `ep` (endpoint) and `action` parameters.
- **TDD Framework**: Built-in Test-Driven Development runner for unit testing endpoints.
- **Magic JavaScript Client**: A proxy-based client that allows calling any endpoint without prior configuration.

## Directory Structure
```
src/RapidBase/          # Core library (compiled into RapidBase.php)
├── Api/                # Base classes for API context and routing
├── Tdd/                # TDD Runner and reporting classes
└── Models/             # Base models (optional)

examples/querybrowser/  # Example application
├── api/v1/
│   ├── Endpoints/      # Custom endpoints for this project
│   ├── Models/         # Project-specific models
│   └── index.php       # API entry point
├── tests/Unit/         # Project-specific unit tests
│   └── .tdd/           # TDD configuration for this project
└── components/         # Frontend JS components
    └── lib/
        └── RapidBaseClient.js  # Magic JS client

docs/API/               # This documentation
```

## Key Concepts

### 1. Endpoints
Endpoints are PHP classes located in `api/v1/Endpoints/`. Each public method represents an action.

### 2. TDD System
Each project can have its own test suite in `tests/Unit/`, configured via `.tdd/config.json`.

### 3. Magic Client
The JavaScript client uses Proxies to dynamically translate method calls into API requests.

---
See [tutorial.md](./tutorial.md) for step-by-step guides.
