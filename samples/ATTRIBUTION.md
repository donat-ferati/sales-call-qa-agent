# Demonstration recording attribution

These recordings are derivatives of the **HarperValleyBank** simulated contact-centre dataset published by **Gridspace and Stanford University**. They are English role-play conversations rather than real insurance sales calls.

- Original source: https://github.com/cricketclub/gridspace-stanford-harper-valley
- Source revision: `0bd721e877c4a85d8c13ff837e68661ea6200a98`
- License: Creative Commons Attribution 4.0 International (CC BY 4.0), https://creativecommons.org/licenses/by/4.0/
- Full license: `LICENSE-CC-BY-4.0.txt` in this directory. The dataset is provided without warranties; no upstream endorsement of this application is implied.

## Included derivatives

| File | Source call | Modification | Duration |
|---|---|---|---|
| `branch-hours-complete.wav` | `ff0296d00e5e4184` | Align agent channel by +2000 ms, mix with caller, resample to 16 kHz mono PCM16 | 53.970 seconds |
| `card-replacement-missing-next-step.wav` | `0002f70f7386445b` | Align agent channel by +2051 ms, mix with caller, resample to 16 kHz mono PCM16 | 51.111 seconds |
| `branch-hours-truncated-demo.wav` | `ff0296d00e5e4184` | First 20 seconds of the aligned mixed recording above; **deliberately truncated derivative** | 20.000 seconds |

The source files are under `data/audio/agent/<call-id>.wav` and `data/audio/caller/<call-id>.wav` at the pinned revision. The agent/caller channel starts differ: alignment comes from the corrected source transcript's `offset_ms - start_ms` for each channel. Mixing preserves the complete common timeline, uses equal unity gain and a latency-compensated peak limiter, and removes no silence. The intentionally truncated file demonstrates unavailable closing evidence; it is not an originally incomplete source call.

The filenames describe intended review scenarios, not guaranteed model judgments. The full branch-hours call supports a basic greeting/request-handling/closing demonstration. The card-replacement call contains no spoken explanation of a replacement action or next step after clarification. The demonstration rubric evaluates communication standards, not the factual correctness of bank opening hours. The separate life-insurance rubric is illustrative and was not supplied or approved by an insurer.

`source/` contains the original channel WAVs, metadata and **human-corrected reference transcripts** solely for provenance and review. They are never imported as generated transcripts. The actual application transcribes each uploaded WAV using local Whisper. `source-checksums.json` records SHA256 hashes of the downloaded source and prepared recordings.

Recreate the derivatives with `powershell -ExecutionPolicy Bypass -File scripts/setup-local-ai.ps1 -SkipOllama -PrepareSamples`. Source transcripts and audio remain covered by CC BY 4.0; application code has its own license.
