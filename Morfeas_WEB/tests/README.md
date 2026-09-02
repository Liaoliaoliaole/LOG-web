# LOG WEB local tests

Run every maintained PHP and JavaScript test from the application test directory:

```bash
./run_tests.sh
```

The runner requires PHP and Node.js. It is a local development command; this
repository does not configure a CI service.

The suite includes production-function regression coverage for the current
configuration contracts:

- Local JSON and FTP Restore warning acknowledgement, lock-time revalidation,
  and zero-write rejection before acknowledgement;
- SDAQ runtime-owned `UNIT`, `CAL_DATE`, and `CAL_PERIOD` fields across Add,
  Edit, Replace, and Local JSON Restore;
- SDAQ Calibration/Scale float32 rules, active-table replacement confirmation,
  and nominal Scale `Period=0` behaviour;
- shared Web/Core daemon-configuration corpus, including 15/16/17-byte device
  names and invalid IPv4 values;
- root helper privilege-boundary checks and shell syntax for deployment scripts.
- fixture parsing for normal, offline, incomplete, and malformed SDAQ, IOBOX,
  and MTI logstat snapshots.

Run this suite before a Web deployment. Hardware-only paths that cannot be
created safely on the target are covered here by production-function tests;
they are not a reason to manufacture an unsafe HIL scenario.
