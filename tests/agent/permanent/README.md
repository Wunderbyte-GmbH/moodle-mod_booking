# Permanent WBAgent Test Suite

This directory contains permanent, architecture-level guardrail tests for the booking AI agent.

Goals:
- detect unnoticed behavior changes in the wbagent stack early
- keep deterministic safety tests independent of real LLM availability
- provide realistic LLM simulation coverage for interpreter behavior
- enforce a minimum inventory baseline of critical tests/features

Scope in wave 1:
- `contracts/`: architecture and inventory contracts
- `llm_sim/`: realistic simulated LLM payload matrix tests
- `tasks/`: task-level validation contract matrix

Important:
- These tests are intentionally stricter than smoke tests.
- If behavior or architecture changes intentionally, update tests in this folder in the same PR.
