This is the folder where you can add booking extensions.


# Run Benchmarks
php public/mod/booking/bookingextension/agent/cli/benchmark_runner.php

# Schnellstart mit Stub-Responses (kein LLM-Call, kein API-Kosten)
php public/mod/booking/bookingextension/agent/cli/benchmark_runner.php --stub

# Mit Label (für Vergleiche)
php public/mod/booking/bookingextension/agent/cli/benchmark_runner.php --label=release-5.1.2

# Als Baseline pinnen
php public/mod/booking/bookingextension/agent/cli/benchmark_runner.php --pin-baseline --baseline-label=stable-5.1

# Anderen Scenario-Set
php public/mod/booking/bookingextension/agent/cli/benchmark_runner.php --scenario-set=core_booking_v1

# Alle Optionen anzeigen
php public/mod/booking/bookingextension/agent/cli/benchmark_runner.php --help
