# SPEC-NOISE-MODEL-REVIEW.md — Critical Review of Aircraft Noise Estimation Model

**Reviewer:** Second-opinion technical review  
**Date:** 2026-07-26  
**Spec under review:** SPEC-NOISE-MODEL.md v1.0 (DRAFT)  
**Verdict:** Contains critical errors in reference levels and speed correction that would produce systematically wrong results. Requires revision before implementation.

---

## 1. CRITICAL ERRORS

### 1.1 Reference Levels (L_ref) Are Systematically Underestimated by ~15 dB (§3.3)

This is the most severe problem in the spec. The L_ref values for FAPP and CLIMBOUT are approximately **15–18 dB too low**, traceable to a derivation error in the back-correction from certification data.

**The error:** The spec derives L_ref (defined as L_Amax at 1 km slant, free-field) from ICAO certification measurements at greater distances. Going from a far measurement point to a nearer reference point requires **adding** both geometric spreading and atmospheric absorption corrections. The spec appears to have **subtracted** them instead.

**Demonstrated for FAPP M_NB:**

The spec cites A320 certification approach = 72.4 dB at 2 km slant (Appendix B). Correct back-correction to 1 km:

```
L_ref = 72.4 + 20×log₁₀(2000/1000) + 3.0×(2000−1000)/1000
      = 72.4 + 6.0 + 3.0
      = 81.4 dBA (at approach power, with ground reflection)
```

Applying corrections: −3 dB for ground reflection (cert uses ground-level mics), −4 dB for idle vs. approach power:

```
L_ref(FAPP, M_NB, free-field, idle) ≈ 81.4 − 3 − 4 ≈ 74 dBA
```

The spec claims **60 dBA** — approximately 14 dB too low.

**Cross-check from real-world data:** Airport noise monitoring stations at ~1 km from threshold (Dublin, Frankfurt, Zürich environmental reports) consistently record L_Amax of **68–75 dBA** for A320-family approaches. The spec's L_ref of 60 dBA at 1 km would mean the aircraft is quieter at 1 km than measured noise monitors show — which is only possible if L_ref were *lower* than direct measurements at that distance.

**Cross-check from the worked example itself:** If we use L_ref = 74 instead of 60 in the Appendix A scenario, the result is 59.1 dBA — within the 55–70 dBA range expected from monitoring data. With the spec's 60, the result (45.1 dBA) is implausibly quiet.

**Demonstrated for CLIMBOUT M_NB:**

From real-world departures: noise monitors at ~600 m slant record ~88–92 dBA for A320-family. Back-correcting to 1 km:

```
L_ref ≈ 90 + 20×log₁₀(600/1000) + 3.0×600/1000
      ≈ 90 − 4.4 + 1.8
      ≈ 87.4 dBA
```

The spec claims **74 dBA** — approximately 13 dB too low.

**Recommended L_ref values (free-field, representative power settings):**

| Phase | H_WB | H_NB | M_NB | RJET | TPROP | LIGHT |
|-------|------|------|------|------|-------|-------|
| CLIMBOUT | 96 | 91 | **88** | 90 | 92 | 79 |
| APPROACH TRANSITION | 82 | 77 | **74** | 78 | 82 | 68 |
| FINAL APPROACH | 78 | 73 | **74** | 74 | 80 | 65 |

(These are approximate; the critical point is they are ~14–18 dB higher than the spec's values.)

### 1.2 Speed Correction Sign Error (§6.1 vs §8.2)

There is a direct contradiction between the formula definition in §6.1 and the implementation in §8.2.

**§6.1 defines:**
```
A_speed = n × 10 × log₁₀(V / V_ref)
```
This is **positive when V > V_ref** (faster = more airframe noise = louder). Physically correct.

**§8.2 defines:**
```
A_speed = −n_exp × 10 × log₁₀(V / V_ref)
```
This is **negative when V > V_ref** (faster = quieter). Physically wrong.

The final formula in §8.1 applies `+ A_speed`. Combined with §8.2's sign convention, a faster aircraft produces a *lower* L_Amax — the opposite of reality.

**Impact in the worked example:** V = 72 m/s vs V_ref = 70 m/s, n = 7.
- Correct (§6.1): A_speed = +0.86 dB → L_Amax increases by 0.86 dB
- Spec uses (§8.2): A_speed = −0.86 dB → L_Amax decreases by 0.86 dB

**Fix:** Remove the negation in §8.2. Use `A_speed = n_exp × 10 × log₁₀(V / V_ref)` and keep `+ A_speed` in the final formula. The §6.1 note "A_speed is positive when aircraft is slower than reference" is itself incorrect — faster aircraft produce more airframe noise.

---

## 2. MAJOR ISSUES

### 2.1 Atmospheric Absorption Coefficient Underestimated (§4.2)

The spec uses α_eff = 3.0 dB/km for all flight phases. This is at the low end of plausible values and likely underestimates absorption by a factor of 2–3 for approach noise.

The spec's own ISO 9613-1 table shows:
- 500 Hz: 1 dB/km
- 1000 Hz: 4 dB/km
- 2000 Hz: 12 dB/km
- 4000 Hz: 40 dB/km

A-weighted approach noise (airframe-dominated) peaks in the **1000–4000 Hz band** where A-weighting is near maximum and atmospheric absorption is 4–40 dB/km. Energy-weighted averaging over this spectrum yields α_eff ≈ **8–15 dB/km**, not 3 dB/km.

The spec cites ISO 9613-2 §7.4 suggesting "2–5 dB/km," but that guidance applies to **industrial noise sources** dominated by low frequencies (50–500 Hz). Aircraft noise — particularly airframe and fan noise — has substantially more high-frequency content.

**Impact at Mannersdorf distances (2–3 km slant):**
- With α = 3.0: A_atm = 6–9 dB
- With α = 8.0: A_atm = 16–24 dB

This is a 10–15 dB difference in predicted levels. Combined with the L_ref error, the total systematic error could exceed 25 dB.

**Recommendation:** Use phase-dependent α_eff:
- CLIMBOUT (more low-frequency jet noise): α_eff ≈ 5–6 dB/km
- FAPP (airframe-dominated, higher frequency): α_eff ≈ 8–10 dB/km

### 2.2 Certification Metric Ambiguity (§3.1, Appendix B)

The spec cites values like "A320 approach: 72.4 dB" and "flyover: 82.1 dB" without specifying the metric. ICAO Annex 16 noise certification uses:

- **EPNdB** (Effective Perceived Noise decibels) — includes tone and duration corrections, ~5–10 dB higher than L_Amax for the same event
- Some sources report **L_Amax** or **SEL** instead

If the 72.4 dB figure is EPNdB (which is typical for certification data sheets), the equivalent L_Amax would be ~65–68 dB — which changes the back-correction significantly. The spec should explicitly state which metric each reference value represents and apply appropriate conversions.

### 2.3 SEL Duration Formula Overestimates by ~2× (§8.2)

```
T_eff ≈ 2 × d_slant_min / V_horizontal
```

This assumes the noise event lasts for the time to traverse twice the slant distance — but the relevant duration is the time the aircraft spends near the closest point of approach, which scales with horizontal distance, not slant distance. For a straight-line pass:

```
T_eff ≈ d_h_min / V_horizontal    (time to traverse ±90° from CPA)
```

The worked example gives T_eff = 43 s using the spec formula. Realistic event durations for aircraft at 1.5–2 km are **15–25 seconds** (confirmed by noise monitoring data). This overestimates SEL by ~3–5 dB.

### 2.4 Lateral Attenuation Formula Direction is Inverted (§4.4)

The spec's formula:
```
A_lateral = 2 × (1 − sin(θ)/0.3)   for sin(θ) < 0.3
```

where sin(θ) = h/d_slant (elevation angle from observer). This gives **more attenuation when the aircraft is higher** (more overhead) and **less when it's near the horizon**.

Physical reality (and ISO 9613-1 §7.3) is the opposite: lateral/ground-screening attenuation is greatest for **shallow propagation angles** (near the horizon), where sound travels a long path close to the ground. The formula should increase A_lateral as elevation angle decreases, not as it increases.

**Practical impact:** Small (0–2 dB) because this correction is minor for typical Mannersdorf geometries. But the formula is conceptually wrong and would produce incorrect gradients.

---

## 3. MODERATE ISSUES

### 3.1 Go-Around / Missed Approach Not Handled (§2.3)

The classification logic categorizes aircraft with V/S > 5 m/s below 3000 m as CLIMBOUT. A go-around at 300 m altitude with high thrust and approach configuration (gear down, flaps extended) would be misclassified. This produces noise characteristics of neither pure CLIMBOUT (wrong config) nor FAPP (wrong thrust).

**Fix:** Add detection: if V/S transitions from negative to positive at low altitude (< 1000 m) and speed < 100 m/s, classify as GO_AROUND with L_ref between FAPP and CLIMBOUT.

### 3.2 Ground Reflection Model Has Limited Physical Basis (§4.3)

The formula `A_ground = 3 × (1 − exp(−d_h/(10×h)))` is presented as derived from ISO 9613-2 Annex A but does not appear in that standard. ISO 9613-2 provides a complex multi-term formula involving source/receiver heights, ground impedance, and frequency bands. The spec's formula captures the qualitative behavior (less ground effect when overhead, more at low angles) but the specific functional form and the 3 dB maximum are unjustified.

**Recommendation:** Flag as "empirical approximation, not ISO-derived." The maximum destructive interference over soft ground for A-weighted broadband is typically 1.5–2.5 dB, not 3 dB (per ISO 9613-2 Table A.1).

### 3.3 Clamping of L_Amax to [20, 100] dBA (§8.2)

The lower clamp of 20 dBA is fine (below ambient). The upper clamp of 100 dBA may be too low for a close overhead departure at 500 m — a heavy jet at 500 m slant on climbout could legitimately produce 95–105 dBA. The clamp should be raised to at least 110 dBA or removed (physical implausibility is self-limiting via the L_ref and attenuation terms).

### 3.4 Turboprop Corrections Are Phase-Dependent but Not Justified (§7.1)

The Q400 correction jumps from +5 dB (CLIMBOUT) to +8 dB (FAPP), implying turboprops get relatively louder on approach. While this is qualitatively correct (propeller RPM stays high on approach for drag/go-around readiness), the +8 dB value is not well-sourced. A Q400 at approach producing 74 + 8 = 82 dBA at 1 km slant seems too high — this would make it louder than an A380 approach (74 + 8 = 82 as well). The correction should be validated.

### 3.5 Missing Supersonic / High-Speed Transit Case

The speed correction formula (with n=7 for approach) could produce extreme values for high-speed military or supersonic aircraft. The ±6 dB clamp limits the damage, but the spec should explicitly exclude or flag aircraft with speed > 250 m/s (Mach > 0.8 at altitude).

---

## 4. MINOR ISSUES AND STYLE

### 4.1 Unit Inconsistency in Atmospheric Absorption (§4.2, §8.2)

- §4.2 defines: `A_atm = α_eff × d_slant / 1000` (with α_eff in dB/km, d_slant in meters)
- §8.2 writes: `A_atm = 0.003 × d_slant` (implicitly 3.0/1000 = 0.003 per meter)

These are numerically equivalent but the change in representation obscures the physical meaning. Recommend keeping the explicit form `α_eff × d_slant / 1000` throughout.

### 4.2 A-Weighting vs EPNL Not Distinguished

The spec consistently uses "dBA" but the model predicts A-weighted maximum SPL (L_Amax). Aviation noise certification and regulatory frameworks typically use EPNL (for certification) or L_den (for environmental assessment). The spec should clarify that L_Amax is a simplification and is not directly comparable to published EPNdB certification values.

### 4.3 Section Cross-References

§6.2 states "These are already incorporated into L_ref (§3.2)." But §3.2 defines categories, not reference levels. Should reference §3.3.

### 4.4 The Spec Claims the Old Model Reference is "Far Too High"

The Appendix A comparison states the old model's 80 dBA at 300 m reference is "far too high for an approach scenario." This is true — but the old model also lacks atmospheric absorption and phase awareness, so its errors partially cancel at typical Mannersdorf distances. The old model's 63 dBA result is closer to reality than the new model's 45 dBA, despite being derived from wrong assumptions. The spec should acknowledge this.

---

## 5. VERIFICATION OF THE 18 dB CORRECTION CLAIM

The spec claims the new model corrects the old model by 18 dB (63.3 → 45.1 dBA) for an A320 on final approach at 450 m altitude, 2 km lateral.

**My assessment of the true value at this geometry:**

| Source | Estimated L_Amax |
|--------|-----------------|
| Old model (spec) | 63.3 dBA |
| New model (spec, with errors) | 45.1 dBA |
| New model (corrected L_ref=74, α=3) | 59.1 dBA |
| New model (corrected L_ref=74, α=8) | 55.0 dBA |
| Direct from cert data (idle, free-field) | ~65–68 dBA |
| Typical noise monitor at similar geometry | 55–70 dBA |
| Best estimate (corrected model with α=5–8) | **55–62 dBA** |

**Conclusion:** The old model overestimated by ~5–10 dB (not 18 dB). The new model as specified *underestimates* by ~10–20 dB. Neither is correct. A properly calibrated new model should predict ~55–62 dBA at this geometry, which represents a **correction of ~3–8 dB** relative to the old model, not 18 dB.

---

## 6. COMPARISON TO STANDARDS

### 6.1 ECAC Doc 29 / ICAO Annex 16 Alignment

The spec's methodology is **conceptually aligned** with Doc 29 but heavily simplified:

| Feature | ECAC Doc 29 | This Spec |
|---------|------------|-----------|
| NPD tables | Full thrust-dependent tables | Single L_ref per phase |
| Distance attenuation | Log-linear interpolation from NPD | Spherical spreading + α |
| Lateral attenuation | Elevation-angle + geometry based | Simplified formula |
| Duration correction | Explicit for SEL | Geometric T_eff estimate |
| Directivity | Installation angle corrections | Flat per-category offset |
| Ground effects | Impedance-based | Empirical formula |

This is acceptable for a screening-level model. The spec should explicitly position itself as "screening level" per Doc 29 terminology (as opposed to "full calculation").

### 6.2 Comparison to Operational Tools

- **FAA AEDT:** Uses full NPD tables with continuous thrust modeling. AEDT at this geometry would give ~55–65 dBA for A320 approach. The spec's corrected model (with fixed L_ref) would agree within ±5 dB.
- **FLULA2 (Swiss):** Radar-track-driven, energy-based SEL model. More accurate due to real track data. Uses frequency-band computation that this spec deliberately omits.
- **NoiseLab (Dutch):** Implements Doc 29 with terrain. Would give similar results to AEDT for flat terrain (Mannersdorf is relatively flat).

---

## 7. RECOMMENDATIONS

### Must Fix Before Implementation

1. **Recalculate all L_ref values** using correct back-correction from certification data. Current values are ~15 dB too low. Cross-check against at least one published noise monitoring dataset.
2. **Fix speed correction sign** in §8.2 to match §6.1's physical rationale.
3. **Use phase-dependent atmospheric absorption** (α ≈ 5–6 dB/km for climbout, 8–10 dB/km for approach).
4. **Fix lateral attenuation** direction or remove the term (its contribution is < 2 dB and the current formula is incorrect).

### Flag as Uncertain, Needs Calibration

5. Turboprop L_ref and type corrections — less published data available.
6. Ground reflection formula — not standard-derived; needs empirical validation.
7. SEL estimation — limited by 60 s polling and simplified T_eff formula.
8. Go-around handling — currently unclassified.

### Missing Entirely

9. **Temperature inversion handling** — acknowledged in §9.4 but no detection mechanism. Vienna Basin winter inversions can add +5–8 dB. Could be partially addressed with METAR data.
10. **Terrain shielding** — the Leithagebirge ridge between Mannersdorf and LOWW may provide 2–5 dB of shielding for low-altitude approaches. A simple terrain profile check could improve accuracy.
11. **Simultaneous events** — no model for overlapping noise from multiple aircraft.
12. **Validation against LOWW published contours** — the spec mentions this (§11.2) but doesn't provide the actual comparison. This should be done before deployment.

---

## 8. SUMMARY OF SEVERITY

| Issue | Severity | Impact on Predicted L_Amax |
|-------|----------|--------------------------|
| L_ref derivation error | **Critical** | −15 to −18 dB (systematically too quiet) |
| Speed correction sign | **Critical** | ±1–2 dB wrong direction |
| Atmospheric absorption α | **Major** | −5 to −10 dB at 2–3 km (too quiet) |
| Certification metric ambiguity | **Major** | ±5–10 dB depending on metric |
| SEL T_eff overestimate | **Major** | +3–5 dB on SEL |
| Lateral attenuation inverted | **Moderate** | ±1 dB |
| Ground reflection not standard | **Moderate** | ±1 dB |
| Go-around unclassified | **Moderate** | Wrong phase → wrong L_ref |

**Net systematic bias of the spec as written:** Approximately **−20 to −30 dB** below true values for approach scenarios. The model would predict 45 dBA where the true value is 55–70 dBA. This would make nearly all flights appear inaudible or barely audible, which contradicts direct observation at Mannersdorf.

---

## 9. OVERALL ASSESSMENT

The spec is well-structured, physically motivated, and identifies the correct set of phenomena to model. The framework (phase classification → reference level → distance attenuation → corrections) is sound and aligns with ECAC Doc 29 methodology at screening level.

However, the numerical values are fundamentally wrong due to a derivation error in the reference levels and an underestimated atmospheric absorption coefficient. These errors compound to produce predictions that are 20–30 dB too quiet — making the "improved" model actually *less* accurate than the naive geometric model it replaces (which, despite its simplicity, predicted a more realistic 63 dBA for the worked example scenario).

**Recommended path forward:**
1. Fix the four critical/major errors identified above.
2. Validate corrected model against LOWW Umweltbericht noise contours.
3. Deploy with L_ref as a configurable parameter for field calibration.
4. Collect 30+ manual noise readings and perform linear regression to calibrate L_ref offset.
