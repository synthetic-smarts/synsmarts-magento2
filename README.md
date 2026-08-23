# synsmarts magento2

The Magento 2 module the synsmarts platform installs into every Magento store
it hosts. This repository is a read-only mirror of the exact bytes the
platform ships; it exists so you can inspect, test, and verify them.

## What's here

| Module | What it does |
|---|---|
| `app/code/Synsmarts/AwsCredentialsIsolation` | Replaces Magento's S3 remote-media credential provider (`Magento\AwsS3\Driver\CachedCredentialsProvider`) via a DI preference. Magento core caches temporary AWS credentials in the shared Magento cache under one fixed key; on infrastructure where the web, cron, and shell workloads run with different AWS identities, that lets one workload silently pick up another's credentials — causing intermittent `AccessDenied` on media and defeating per-workload least privilege. This module keeps credentials in a pod-local file cache keyed by the workload's own role. Two classes, DI config, and a registration file; no database schema, no setup patches, no admin UI. |

The platform injects this tree into your built store image at
`app/code/Synsmarts/AwsCredentialsIsolation` and the image build fails unless
the module is registered and its DI preference compiled.

## Verify the published bytes

The `app/code/Synsmarts/AwsCredentialsIsolation/` tree in any tag of this
repository is byte-identical to the directory the platform injects into your
store's runtime image at build time. From your synsmarts shell:

```sh
d=$(mktemp -d) && git clone --quiet --depth 1 --branch <tag> \
  https://github.com/synthetic-smarts/synsmarts-magento2 "$d" \
  && diff -r "$d/app/code/Synsmarts/AwsCredentialsIsolation" \
      app/code/Synsmarts/AwsCredentialsIsolation \
  && echo "verified: identical"
```

Run it from your store root. Your workspace carries the copy it was
seeded with at provisioning, so after a shim update it can lag the image
your production pods run — a workspace mismatch means your workspace copy
is older or locally modified, never that production drifted. Tags are
re-pointed if a mirror is ever reconverged after tampering.

## Read-only mirror

Development happens in the synsmarts platform monorepo; every change there is
automatically published here, and each publish records the source commit.
Pushes and pull requests to this repository are not accepted — anything
pushed here directly is overwritten by the next publish. Bug reports and
questions are welcome as issues.

## License

MIT (see `LICENSE`).
