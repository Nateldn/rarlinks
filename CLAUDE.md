# Working methodology

This plugin has had several "found it" fixes that turned out to be wrong,
or not the actual cause, when tested against the live site. Going forward:

- Don't declare a root cause from the symptom alone. Verify the actual
  mechanism (read the real code path, check WordPress core behavior
  precisely — not from memory/assumption) before saying "found it."
- Before proposing a fix for a live-site bug, ask for or get the actual
  evidence first: the real request/response, error logs, DB state —
  not just a description of the symptom.
- Be explicit about confidence: label a fix as "confirmed" only once
  verified against real behavior/evidence, vs "untested theory"
  otherwise. Don't present a guess with more confidence than it's earned.
- Expect to test/verify before calling something done, not after the user
  reports it still broken.
