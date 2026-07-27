# SPEC-NOISE-MODEL.md — Research-Grade Aircraft Noise Estimation Model

**Version:** 1.0  
**Status:** DRAFT — Pending Human Review  
**Date:** 2026-07-26  
**Scope:** Replacement for the naive geometric model described in SPEC.md §9.9  

---

## Table of Contents

1. [Noise Sources on an Aircraft](#1-noise-sources-on-an-aircraft)
2. [Flight Phase Classification](#2-flight-phase-classification)
3. [Reference Noise Levels](#3-reference-noise-levels)
4. [Distance Attenuation Model](#4-distance-attenuation-model)
5. [Directivity, Source Height, and Lateral Effects](#5-directivity-source-height-and-lateral-effects)
6. [Flight Dynamics Adjustments](#6-flight-dynamics-adjustments)
7. [Aircraft-Type Corrections](#7-aircraft-type-corrections)
8. [Final Formula Structure](#8-final-formula-structure)
9. [Atmospheric Conditions](#9-atmospheric-conditions)
10. [Uncertainty and Limitations](#10-uncertainty-and-limitations)
11. [Validation Strategy](#11-validation-strategy)
12. [Computational Complexity](#12-computational-complexity)
13. [Open Questions](#13-open-questions)
A. [Appendix A — Worked Example](#appendix-a--worked-example)
B. [Appendix B — Standards and References](#appendix-b--standards-and-references)

---

## Preamble: Design Philosophy

This model produces **physically plausible, relative noise estimates** for aircraft overflying Mannersdorf am Leithagebirge. It is not — and cannot be, given the available data — a substitute for calibrated ground-based noise monitoring (e.g., Brüel & Kjær Type 4951 terminals as deployed at LOWW). It is, however, a substantial improvement over the existing single-parameter geometric model, incorporating:

- Aircraft-type-dependent source levels
- Flight-phase-aware reference points
- Atmospheric absorption (broadband A-weighted approximation)
- Lateral attenuation per ISO 9613-1
- Speed and thrust corrections
- Ground reflection effects

The model is **computationally trivial** (<1 ms per position in PHP) and **fully deterministic** given the same inputs, enabling consistent relative comparison across flights and time periods.

---

## 1. Noise Sources on an Aircraft

### 1.1 Engine Noise

**Turbofan engines** (all commercial jets at LOWW) produce noise from:

| Source | Mechanism | Dominant Frequency | Phase Relevance |
|--------|-----------|-------------------|-----------------|
| Fan (front) | Rotor-stator interaction, tip vortices | 500–4000 Hz (BPF harmonics) | Departure (high N1), approach (low N1, still audible) |
| Jet exhaust | Turbulent mixing of high-velocity exhaust with ambient air | 100–1000 Hz (broadband) | Departure (high thrust), negligible on approach |
| Turbine | Blade-passing frequency of HP/LP turbines | 1000–8000 Hz | All phases (relatively constant, masked by fan/jet) |
| Combustion | Low-frequency rumble from combustor dynamics | 50–200 Hz | Departure (high fuel flow) |

**Key point:** Engine noise scales with **thrust setting** (approximately proportional to N1³ for jet mixing and N1⁵ for fan noise at high power). On approach, thrust is near idle (~25–30% N1), so engine noise drops by 15–25 dB relative to takeoff power [ECAC Doc 29, Vol. 1, §3.2].

**Turboprop engines** (e.g., Dash 8-Q400, ATR 72) are dominated by **propeller noise**:
- Fundamental: blade-passing frequency (BPF = RPM × blade_count / 60), typically 85–135 Hz
- Harmonics up to 10th, with A-weighting emphasizing 500–2000 Hz
- Propeller noise scales with tip Mach number (~V_tip⁵ to V_tip⁸) and is less sensitive to thrust changes
- At approach power, turboprops can be **louder than equivalent jets** in the low-frequency range [SAE ARP 866A, §6.4]

**Regional jets** (CRJ-200, E170/175/190): smaller fan diameter, higher specific thrust → higher jet velocity → relatively louder per unit thrust. CRJ-200 is notably loud on departure due to aft-mounted engines with minimal acoustic treatment [ICAO Annex 16, Vol. I, Part II, Chapter 4].

### 1.2 Airframe Noise

Airframe noise arises from turbulent airflow over the aircraft structure and becomes **dominant during approach** when engine thrust is near idle [Dobrzynski, "Almost 40 Years of Airframe Noise Research", AIAA 2010].

| Source | Mechanism | A-Weighted Contribution | Configuration |
|--------|-----------|------------------------|---------------|
| Leading-edge slats | Cavity resonance, slot flow | 2–6 dB above clean | Flaps extended |
| Trailing-edge flaps | Gap noise, side edges | 3–8 dB above clean | Flaps extended |
| Landing gear | Bluff-body vortex shedding | 5–10 dB above clean | Gear down |
| Wing trailing edge | Turbulent boundary layer scattering | Baseline (always present) | All phases |
| High-lift device side edges | Vortex formation at flap/slat tips | 2–4 dB | Approach config |

### 1.3 Dominance by Phase

```
DEPARTURE/CLIMBOUT:  Engine >> Airframe   (engine noise 10–20 dB above airframe)
APPROACH/FINAL:      Airframe ≥ Engine    (airframe noise 0–5 dB above engine at idle)
CRUISE (high alt):   Engine ≈ Airframe    (both weak; irrelevant for ground observer)
```

### 1.4 Typical A-Weighted Spectra (A320 Family)

- **Takeoff (full thrust):** Peak energy 200–500 Hz (jet mixing), strong broadband 500–4000 Hz (fan). A-weighted peak ~250–500 Hz.
- **Approach (idle thrust, gear/flaps down):** Peak energy shifts to 1000–4000 Hz (airframe), reduced low-frequency content. A-weighted peak ~1000–2000 Hz.

[Ref: ICAO Doc 9911, "Report on Noise from Civil Aircraft in Flight", Figure III-2-3]

---

## 2. Flight Phase Classification

### 2.1 Available Inputs

From each `flight_positions` record:
- `altitude_m` (barometric or geometric)
- `vertical_rate_mps` (signed, positive = climbing)
- `speed_mps` (groundspeed from velocity field)
- `heading_deg` (true track)
- `distance_km` (from Mannersdorf center)
- `lat`, `lon` (position)

Derived:
- `dist_to_airport_km` — haversine to LOWW (48.1103°N, 16.5697°E)

### 2.2 Phase Definitions (Mannersdorf-Relevant)

| Phase | Code | Typical Altitude | Typical V/S | Notes |
|-------|------|-----------------|-------------|-------|
| GROUND | `GND` | 0 m (on_ground=true) | 0 | Not relevant for noise at distance |
| TAKEOFF ROLL | `TOFL` | 0–30 m | 0–5 m/s | On runway, high thrust |
| CLIMBOUT | `CLMB` | 30–3000 m | > 5 m/s | Gear retracting, high thrust |
| APPROACH TRANSITION | `APTR` | 1500–4000 m | -2 to -8 m/s | Configuring, speed reducing |
| FINAL APPROACH | `FAPP` | 150–1500 m | -3 to -7 m/s | Glideslope, gear down, landing config |
| OVERFLIGHT | `OVFL` | > 4000 m | any | Cruise or high-altitude transit |

**Note:** For Mannersdorf (~30 km from LOWW), the most commonly observed phases are FAPP (arrivals on RWY 16 descending through 450–1500 m), CLMB (departures on RWY 29 climbing through 500–3000 m), and OVFL (cruise above 6000 m, generally inaudible).

### 2.3 Classification Logic

```
function classifyPhase(altitude_m, vertical_rate_mps, speed_mps,
                       dist_to_airport_km, on_ground):

    if on_ground and altitude_m < 30:
        return GND

    if dist_to_airport_km < 5 and altitude_m < 30:
        return TOFL

    if vertical_rate_mps > 5 and altitude_m < 3000:
        return CLMB

    if vertical_rate_mps < -2 and altitude_m < 1500:
        return FAPP

    if vertical_rate_mps < -1 and altitude_m < 4000:
        return APTR

    if altitude_m >= 4000:
        return OVFL

    // Fallback — ambiguous, treat as overflight
    return OVFL
```

### 2.4 Speed-Based Refinement

```
if phase == FAPP and speed_mps > 100:
    // Too fast for final approach — likely en-route descent
    phase = OVFL

if phase == CLMB and speed_mps < 50 and vertical_rate_mps < 0:
    phase = APTR
```

---

## 3. Reference Noise Levels

### 3.1 Sourcing Reference Data

The gold standard is the **ANP (Aircraft Noise and Performance) database** maintained by ECAC for the Doc 29 methodology [ECAC Doc 29, 4th Edition, 2016]. ANP provides noise-power-distance (NPD) tables at standardized conditions but is **not freely available** in bulk.

Instead, we use:

1. **ICAO Annex 16, Volume I** noise certification data (publicly published):
   - Approach point: 2,000 m from threshold, 120 m altitude, on glideslope
   - Flyover point: 6,500 m from start of roll, 305 m altitude
   - Lateral point: 450 m from runway centerline, 1.2 m observer height

2. **Published research** correlating certification data with in-service levels:
   - [Nijboer, 2014, "Aircraft noise levels around airports"]
   - [Schäffer et al., 2012, "FLULA2 aircraft noise model"]

3. **FAA AEDT/INM default values** (documented in AEDT technical manuals)

### 3.2 Aircraft Categories

| Category | Code | Examples | Weight Class |
|----------|------|----------|-------------|
| Heavy Widebody | `H_WB` | A380, B744, B77W, A359, B789 | > 200t MTOW |
| Heavy Narrowbody | `H_NB` | B752, B753 | 100–200t |
| Medium Narrowbody | `M_NB` | A320, A321, B738, B739, B38M, A21N, BCS3 | 50–100t |
| Regional Jet | `RJET` | E170, E190, E195, CRJ2, CRJ7, CRJ9 | 25–55t |
| Turboprop | `TPROP` | DH8D (Q400), AT76, AT45 | 15–30t |
| Light / Unknown | `LIGHT` | Cessna, Piper, etc. | < 15t |

### 3.3 Reference L_Amax at 1 km Slant Distance (Free-Field)

These are A-weighted maximum sound pressure levels at 1000 m slant distance from the source, in free-field (no ground reflection), for a representative aircraft in each category:

| Phase | H_WB | H_NB | M_NB | RJET | TPROP | LIGHT |
|-------|------|------|------|------|-------|-------|
| CLIMBOUT (high thrust) | 82 | 77 | 74 | 76 | 78 | 65 |
| APPROACH TRANSITION | 72 | 67 | 64 | 68 | 72 | 58 |
| FINAL APPROACH (idle) | 68 | 63 | 60 | 64 | 70 | 55 |

All values in **dBA**. OVERFLIGHT at cruise altitude (>6000 m) produces < 35 dBA at ground — not computed.

**Derivation notes:**
- **CLIMBOUT M_NB (74 dBA):** Derived from A320 ICAO certification flyover data (~82–84 dB at 6.5 km from start of roll at ~300 m altitude), back-corrected to 1 km slant using standard spreading [EASA TCDS EASA.A.064].
- **FAPP M_NB (60 dBA):** Derived from A320 certification approach (~72–74 dB at 2 km from threshold at 120 m altitude). Slant ≈ 2.0 km. Corrected to 1 km: ~60 dBA for airframe-dominated configuration.
- **Turboprops on approach** are notably louder (propeller RPM stays high for drag/go-around readiness). Q400 at 1 km approach: ~70 dBA [Bombardier Q400 noise certification].
- **Regional jets** (especially CRJ-200): higher specific thrust, less acoustic treatment → approach noise comparable to medium narrowbody at higher thrust.

### 3.4 Fallback Hierarchy

```
1. Exact aircraft type match (ADSB.lol → type-specific L_ref from §7 tables)
2. Category-based lookup (OpenSky category field → table above)
3. Default: M_NB (most common type at LOWW)
```

---

## 4. Distance Attenuation Model

### 4.1 Geometric Spreading

For a point source with spherical spreading:

```
ΔL_geom = 20 × log₁₀(d / d_ref)    [dB]
```

This gives **6.02 dB per doubling of distance**. We use spherical spreading as the base, with ground reflection as a separate correction — consistent with ISO 9613-2 and ECAC Doc 29 for distances > 100 m.

**Slant distance:**
```
d_slant = √(d_horizontal² + h_source²)    [m]
```

### 4.2 Atmospheric Absorption

Per **ISO 9613-1:1993** and **SAE ARP 866A**, absorption coefficient α (dB/km) is frequency-dependent:

| Frequency | α at ISA (15°C, 70% RH) |
|-----------|------------------------|
| 500 Hz | ~1 dB/km |
| 1000 Hz | ~4 dB/km |
| 2000 Hz | ~12 dB/km |
| 4000 Hz | ~40 dB/km |
| 8000 Hz | ~100+ dB/km |

For A-weighted broadband aircraft noise, the effective absorption coefficient is a weighted average over the spectrum:

**Effective broadband A-weighted absorption: α_eff ≈ 3.0 dB/km** at ISA conditions.

Consistent with: [SAE ARP 866A, Table 1], [ISO 9613-2:1996, §7.4] (suggests 2–5 dB/km), [ECAC Doc 29, Vol. 1, §A.3.2].

**Correction formula:**
```
A_atm = α_eff × d_slant / 1000    [dB]
```

**This is a significant correction.** At 3 km slant, atmospheric absorption reduces the level by ~6 dB vs. pure geometric spreading. The old model (pure geometric) significantly over-estimated noise at distance.

### 4.3 Ground Reflection

Sound arrives via direct and ground-reflected paths. The interference depends on source/receiver heights, ground impedance, and frequency.

**Mannersdorf ground:** Agricultural (fields, vineyards) — **soft ground** (flow resistivity ~100–300 kPa·s/m²). Receiver height: ~1.5 m (standing adult).

**Simplified model** (per ISO 9613-2, Annex A, adapted for elevated sources):

```
A_ground = 3 × (1 − exp(−d_h / (10 × h_source)))    [dB]
```

| Condition | d_h vs h_source | A_ground |
|-----------|----------------|----------|
| Nearly overhead | d_h << 10·h | ≈ 0 dB |
| Moderate angle | d_h ≈ 10·h | ≈ −1.9 dB |
| Low elevation | d_h >> 10·h | ≈ −3 dB (max destructive) |

Over soft ground, the reflected wave is partially absorbed, causing net destructive interference (up to −3 dB A-weighted broadband).

### 4.4 Lateral Attenuation

When the source is not overhead, additional attenuation occurs due to ground screening and vegetation at low elevation angles.

```
sin(θ) = h_source / d_slant

if sin(θ) > 0.3:       // > ~17° above horizon
    A_lateral = 0
else:
    A_lateral = 2 × (1 − sin(θ) / 0.3)    [dB]   (0 to −2 dB)
```

For typical Mannersdorf scenarios (aircraft at 500–2000 m altitude, 0–3 km horizontal), θ is usually 15°–60°, so lateral attenuation is 0–1 dB.

---

## 5. Directivity, Source Height, and Lateral Effects

### 5.1 Engine Installation Directivity

| Installation | Types | Effect |
|-------------|-------|--------|
| Under-wing (pylon) | A320, B737, A330, B777, A380 | Wing shields upward radiation; downward +1–2 dB |
| Aft fuselage | CRJ-200, MD-80, B717, E135 | Less wing shielding; +1–3 dB at ground |
| Over-wing | Honda HA-420 (rare) | Significant ground shielding; −3–5 dB |

**Correction:**

```
ΔL_install = {
    "underwing":   0.0 dB,   // reference (most common)
    "aft_mount":  +1.5 dB,
    "turboprop":  +1.0 dB,   // propeller more omnidirectional
    "unknown":     0.0 dB
}
```

### 5.2 Point Source Approximation

At d_slant > 500 m (our minimum relevant distance), the aircraft subtends < 5° — point source assumption is excellent for all cases of interest.

### 5.3 ISO 9613 Lateral Attenuation (Full Formula)

The full ISO 9613-1 §7.3 formula is designed for ground-level industrial sources. For elevated aircraft, the simplified approach in §4.4 is more appropriate and is used in operational models like FLULA2 [Schäffer et al., 2012].

---

## 6. Flight Dynamics Adjustments

### 6.1 Speed Effect on Airframe Noise

Airframe noise scales as:

```
A_speed = n × 10 × log₁₀(V / V_ref)    [dB]
```

where V is groundspeed, and n is a velocity exponent depending on configuration:

| Phase | V_ref (m/s) | n | Rationale |
|-------|-------------|---|-----------|
| CLIMBOUT | 90 (~175 kt) | 5 | Clean config; engine dominates |
| APPROACH TRANSITION | 75 (~145 kt) | 6 | Partial config |
| FINAL APPROACH | 70 (~136 kt) | 7 | Full landing config; airframe very speed-sensitive |

**Example:** A320 on final at 80 m/s (vs. ref 70 m/s):
```
A_speed = 7 × 10 × log₁₀(80/70) = 7 × 10 × 0.058 = +4.1 dB
```

Clamped to ±6 dB maximum.

### 6.2 Thrust Effect on Engine Noise

Engine noise scales non-linearly with thrust (ECAC Doc 29 / ANP methodology). Since we lack thrust data, we estimate from flight phase:

| Phase | Est. Thrust | Engine Noise vs Climb |
|-------|------------|----------------------|
| CLIMBOUT | 85–95% N1 | 0 dB (reference) |
| APPROACH TRANSITION | 40–60% N1 | −8 dB |
| FINAL APPROACH | 25–35% N1 | −15 dB |

**These are already incorporated into L_ref (§3.2).** No additional thrust correction is needed except:

**Climbout V/S adjustment** (optional, captures non-standard climb power):
```
if phase == CLIMBOUT:
    A_thrust = 3 × (vertical_rate_mps − 10) / 10    [dB]
    A_thrust = clamp(A_thrust, −3, +3)
```

### 6.3 Configuration Effect

Implicit in phase-dependent L_ref: CLIMBOUT assumes clean config, FAPP assumes full landing config. No separate correction needed.

---

## 7. Aircraft-Type Corrections

### 7.1 Correction Factor Table

Relative to **Medium Narrowbody (M_NB = 0 dB)**:

| Type Code | Category | ΔL_type | Rationale |
|-----------|----------|---------|-----------|
| A388, A380 | H_WB | +8 | 4× engines, ~575t MTOW |
| B744, B748 | H_WB | +7 | 4× engines, ~400t |
| B772, B773, B77W | H_WB | +6 | 2× GE90-115B, ~350t |
| A359, A35K | H_WB | +5 | Advanced acoustics, ~280t |
| B788, B789, B78X | H_WB | +4 | Chevrons, ~230t |
| B752, B753 | H_NB | +3 | High specific thrust |
| A319, A320, A321 | M_NB | 0 | Reference |
| B737, B738, B739 | M_NB | 0 | Similar to A320 |
| B38M, B39M | M_NB | −1 | Newer engines |
| A20N, A21N | M_NB | −1 | PW1100G/LEAP improved acoustics |
| BCS1, BCS3 (A220) | M_NB | −2 | Geared turbofan, notably quiet |
| E170, E175 | RJET | −1 | Smaller, 2× CF34 |
| E190, E195 | RJET | +1 | Larger E-Jet |
| CRJ2 | RJET | +2 | Aft-mounted, minimal acoustics |
| CRJ7, CRJ9, CRJX | RJET | +1 | Improved over CRJ2 |
| E135, E145 | RJET | +1 | Small regional, aft-mounted |
| DH8D (Q400) | TPROP | +5 (CLMB), +8 (FAPP) | Propeller noise dominant |
| AT72, AT75, AT76 | TPROP | +4 (CLMB), +7 (FAPP) | Similar to Q400 |
| AT45 | TPROP | +3 (CLMB), +6 (FAPP) | Smaller ATR |

### 7.2 Type Resolution Logic

```
function resolveTypeCorrection(aircraft_type, category):
    // 1. Exact type match
    if aircraft_type in TYPE_CORRECTION_TABLE:
        return TYPE_CORRECTION_TABLE[aircraft_type]
    
    // 2. Type family match (first 2–3 chars)
    prefix = aircraft_type[0:3]
    if prefix in FAMILY_CORRECTION_TABLE:
        return FAMILY_CORRECTION_TABLE[prefix]
    
    // 3. Category-based fallback
    if category in CATEGORY_CORRECTION_TABLE:
        return CATEGORY_CORRECTION_TABLE[category]
    
    // 4. Default (M_NB equivalent)
    return 0
```

### 7.3 Data Sources

- **EASA Type Certificate Data Sheets** — freely available, contain noise certification levels
- **FAA NIRS defaults** — FAA Order 1050.1F Desk Reference
- **ANP database** (restricted) — most comprehensive, ECAC Doc 29 compliant
- **Airport noise contour studies** — validation data
- **Research:** [Bertsch et al., 2014], [Huff, 2012, "Aircraft noise prediction: state of the art"]

---

## 8. Final Formula Structure

### 8.1 Instantaneous L_Amax (per position sample)

For each `flight_positions` record, the estimated **maximum A-weighted SPL** at the observer (Mannersdorf center):

```
L_Amax = L_ref(phase, category)
       − A_geom
       − A_atm
       + ΔL_type
       + ΔL_install
       − A_ground
       − A_lateral
       + A_speed
       − A_thrust
```

**Expanded computation:**

```
Inputs:
  h        = altitude_m
  d_h      = distance_km × 1000              // horizontal distance, meters
  d_slant  = √(d_h² + h²)                   // slant distance, meters
  V        = speed_mps
  v_s      = vertical_rate_mps (signed)
  phase    = classifyPhase(h, v_s, V, d_airport, on_ground)
  cat      = resolveCategory(aircraft_type, opensky_category)

Computations:
  A_geom     = 20 × log₁₀(d_slant / 1000)
  A_atm      = 0.003 × d_slant
  A_ground   = 3 × (1 − exp(−d_h / (10 × h)))         // 0 to 3 dB (subtracted)
  sin_theta  = h / d_slant
  A_lateral  = (sin_theta < 0.3) ? 2×(1 − sin_theta/0.3) : 0
  V_ref      = SPEED_REF[phase]   // 90, 75, or 70
  n_exp      = SPEED_EXP[phase]   // 5, 6, or 7
  A_speed    = −n_exp × 10 × log₁₀(V / V_ref)
  A_speed    = clamp(A_speed, −6, +6)
  A_thrust   = 0
  if phase == CLIMBOUT:
      A_thrust = −3 × (v_s − 10) / 10
      A_thrust = clamp(A_thrust, −3, +3)

Final:
  L_Amax = L_ref − A_geom − A_atm + ΔL_type + ΔL_install
         − A_ground − A_lateral + A_speed − A_thrust
  L_Amax = clamp(L_Amax, 20, 100)
```

**Note on sign convention:** A_speed is positive when aircraft is slower than reference (less airframe noise → lower L_Amax), negative when faster (more airframe noise → higher L_Amax). In the formula, `+A_speed` adds the correction (which is itself signed).

### 8.2 Sound Exposure Level (SEL) for a Single Flyover

SEL integrates noise energy over the entire event, normalized to 1 second:

```
SEL = L_Amax + 10 × log₁₀(T_eff)
```

**Estimating T_eff from flight geometry:**

```
T_eff ≈ 2 × d_slant_min / V_horizontal
```

**Example:** A320 at 70 m/s, closest slant distance 1500 m:
```
T_eff ≈ 2 × 1500 / 70 ≈ 43 s
SEL ≈ L_Amax + 10 × log₁₀(43) ≈ L_Amax + 16.3 dB
```

**Decision:** With 60-second polling, we cannot meaningfully integrate. We will:
1. Compute L_Amax per position sample
2. Record **peak L_Amax** across all samples as the flight's noise estimate
3. Estimate SEL from peak L_Amax using geometric T_eff approximation
4. Acknowledge SEL accuracy is limited by polling resolution

### 8.3 Equivalent Continuous Level (L_eq) for Time Averaging

```
L_eq,T = 10 × log₁₀( (1/T) × Σ 10^(SEL_i / 10) )
```

Using our peak L_Amax approximation with typical +16 dB SEL offset:
```
L_eq,T ≈ 10 × log₁₀( (1/T) × Σ 10^((L_Amax_i + 16) / 10) )
```

### 8.4 Day-Evening-Night Level (L_den)

Per EU Environmental Noise Directive 2002/49/EC:

```
L_den = 10 × log₁₀( (12×10^(L_day/10) + 4×10^((L_evening+5)/10)
                     + 8×10^((L_night+10)/10)) / 24 )

where:
  L_day     = L_eq for 07:00–19:00
  L_evening = L_eq for 19:00–23:00
  L_night   = L_eq for 23:00–07:00
```

This is the EU-standard metric for environmental noise assessment and directly addresses the project's core question.

---

## 9. Atmospheric Conditions

### 9.1 Default: ISA Standard Atmosphere

**α_eff = 3.0 dB/km** assumes ISA conditions (15°C, 70% RH, 101.325 kPa).

### 9.2 Seasonal/Time-of-Day Correction (Optional)

Mannersdorf climate (Pannonian, eastern Austria):

| Season | Temp | Humidity | α_eff |
|--------|------|----------|-------|
| Summer (Jun–Aug) | 20–30°C | 50–60% | 3.5 dB/km |
| Winter (Dec–Feb) | 0–5°C | 80–90% | 2.5 dB/km |
| Spring/Autumn | 10–20°C | 60–70% | 3.0 dB/km |
| Night (any season) | cooler | higher RH | −0.5 from seasonal value |

```
if month in [6,7,8]:       α_eff = 3.5
elif month in [12,1,2]:    α_eff = 2.5
else:                      α_eff = 3.0

if hour < 6 or hour > 21:  α_eff -= 0.5
```

**Impact:** ±0.5 dB/km × 2 km = ±1 dB. Within uncertainty bounds; optional.

### 9.3 Wind Effects (Not Modeled)

Wind refraction: ±2–3 dB for moderate winds. No wind data available. Acknowledged as uncertainty.

### 9.4 Temperature Inversion (Not Modeled)

Common in Vienna Basin in winter. Can cause +3–8 dB anomalous propagation. Acknowledged as limitation.

---

## 10. Uncertainty and Limitations

### 10.1 Uncertainty Budget

| Source of Uncertainty | 1σ Estimate | Notes |
|----------------------|-------------|-------|
| Reference level L_ref | ±2.0 dB | Intra-type variation; engine wear |
| Aircraft type correction | ±1.5 dB | A320ceo vs A320neo etc. |
| Phase classification | ±2.0 dB | Misclassification changes L_ref by 4–7 dB |
| Atmospheric absorption | ±1.0 dB | Actual vs ISA conditions |
| Ground reflection model | ±1.0 dB | Simplified soft-ground model |
| Speed/thrust estimation | ±1.5 dB | No direct thrust data |
| Geometric spreading | ±0.5 dB | Well-established |
| Lateral attenuation | ±0.5 dB | Simplified model |
| **Total (RSS)** | **±4.1 dB** | Root-sum-square |

**Expected accuracy:** ±4 dB (1σ) individual events. ±2 dB for **relative comparison** (same type, similar geometry — systematic biases cancel).

### 10.2 Most Reliable

- Relative ranking between similar flights
- Trend analysis over weeks/months (systematic errors cancel)
- Phase-dependent patterns (departures vs approaches)
- Type-dependent patterns (A380 vs A320)

### 10.3 Most Uncertain

- Absolute dBA values (not a substitute for a sound level meter)
- Individual SEL (limited by 60s polling)
- Low-altitude close passes (ground reflection simplified)
- Turboprops (less robust reference data)
- Unusual profiles (go-arounds, missed approaches)

### 10.4 Cannot Capture

- Tonal components (CRJ whine, A380 rumble)
- Impulsive events (thrust reversers)
- Directivity lobes
- Meteorological anomalies (inversions, wind shear, rain)
- Topographic effects (Leithagebirge hills; would need GIS + ray-tracing)
- Simultaneous flight superposition

### 10.5 Mandatory Disclaimer

The UI and documentation must state:

> **"Estimated noise levels are model predictions based on aircraft position, type, and flight phase. They are not calibrated measurements and should not be used for regulatory compliance, legal proceedings, or health impact assessment. Accuracy is approximately ±4 dB for individual events. The model is designed for trend analysis and relative comparison across flights and time periods."**

---

## 11. Validation Strategy

### 11.1 Cross-Check Against Manual Noise Readings

The `noise_readings` table contains user-submitted dB measurements with timestamps:

1. **Correlate** each reading with closest-in-time flight
2. **Compare** reported dB with model L_Amax
3. **Compute** bias (mean difference) and scatter (std dev)
4. **Calibrate** L_ref if systematic bias > 2 dB detected

**Minimum sample size:** ~30 correlated readings for meaningful statistics.

### 11.2 Cross-Check Against LOWW Noise Contour Maps

Vienna Airport publishes noise exposure contours (L_den, L_night) in Umweltbericht:

1. Obtain LOWW noise contour maps
2. Extract predicted L_den at Mannersdorf coordinates
3. Compute L_den from our model using historical data
4. Compare: should be within ±3 dB for same flight mix

### 11.3 Cross-Check Against FAA AEDT/INM

One-time validation: model a representative A320 approach to RWY 16 in AEDT, extract L_Amax at Mannersdorf, compare with our model.

### 11.4 Sanity Checks (Must Pass)

| Check | Expected | Tolerance |
|-------|----------|----------|
| A380 louder than A320 | L_Amax(A380) > L_Amax(A320) same conditions | > 3 dB |
| CRJ-200 louder than E175 | L_Amax(CRJ2) > L_Amax(E170) | > 1 dB |
| Departure louder than approach | L_Amax(CLMB) > L_Amax(FAPP) same distance | > 8 dB |
| Closer = louder | L_Amax at 1 km > L_Amax at 3 km | > 6 dB per halving |
| High overflight inaudible | L_Amax at 10 km altitude | < 40 dBA |
| Monotonic with distance | L_Amax decreases with d_slant | No inversions |
| Physical bounds | 20 < L_Amax < 100 dBA | Hard clamp |

### 11.5 Regression Testing

1. Run model on all historical flights (backfill)
2. Compute distribution: mean, outliers
3. Expected: most flights 45–65 dBA; few > 70 dBA; none > 85 dBA
4. Flag flights > 80 dBA for manual review

---

## 12. Computational Complexity

### 12.1 Operations Per Position Sample

| Operation | Count | Notes |
|-----------|-------|-------|
| Square root | 1 | d_slant |
| Log₁₀ | 2 | Geometric spreading, speed |
| Exponential | 1 | Ground reflection |
| Trigonometric | 0 | sin_theta via division |
| Multiplications | ~10 | Constants, corrections |
| Additions | ~15 | Summing terms |
| Comparisons | ~8 | Phase, clamping |
| Table lookups | 3–5 | L_ref, type, install, speed |

**Total:** ~40 floating-point operations per position.

### 12.2 Estimated Execution Time (PHP 8.3)

- ~10⁸ operations/second (interpreter overhead)
- 40 ops → **~0.4 µs** pure computation
- With overhead: **~0.5–1.0 ms** per position
- Per flight (10–20 samples): **5–20 ms**
- Per poll cycle (~50 flights): **250–1000 ms** (within 60s interval)

### 12.3 Optimization Strategies

1. **Pre-compute slant distance** as generated column
2. **Cache type corrections** in `aircraft` table
3. **Batch computation** per flight
4. **Skip cruise overflights** (altitude > 6000 m)

### 12.4 Database Schema Additions

```sql
ALTER TABLE flight_positions ADD COLUMN slant_distance_m INT UNSIGNED
    GENERATED ALWAYS AS (SQRT(POW(distance_km*1000, 2) + POW(altitude_m, 2))) STORED;
ALTER TABLE flight_positions ADD COLUMN estimated_lamax DECIMAL(4,1) DEFAULT NULL;
ALTER TABLE flight_positions ADD COLUMN flight_phase
    ENUM('GND','TOFL','CLMB','APTR','FAPP','OVFL') DEFAULT NULL;

ALTER TABLE flights ADD COLUMN peak_lamax DECIMAL(4,1) DEFAULT NULL;
ALTER TABLE flights ADD COLUMN estimated_sel DECIMAL(4,1) DEFAULT NULL;
ALTER TABLE flights ADD COLUMN noise_model_version VARCHAR(16) DEFAULT NULL;
```

---

## 13. Open Questions

### 13.1 Requires Human Review

1. **Reference level calibration** — L_ref values should be validated against at least one known measurement before deployment. *Recommendation:* Deploy, collect manual readings for 2–4 weeks, calibrate if bias > 2 dB.

2. **ceo vs neo granularity** — A320neo is ~2 dB quieter on approach than A320ceo. *Recommendation:* Start with family-level; refine if engine data becomes available.

3. **Turboprop handling** — Q400/ATR72 are louder on approach than jets; reference data is less robust. *Recommendation:* Flag as "lower confidence" in UI.

4. **SEL vs L_Amax for reporting** — L_Amax is more intuitive; SEL is more physically meaningful. *Recommendation:* L_Amax in flight list; SEL + L_eq on detail/stats pages.

5. **L_den computation** — EU-standard metric. *Recommendation:* Yes, after 30+ days of data.

6. **Ground reflection** — Leithagebirge hills may cause reflections. *Recommendation:* Ignore terrain for v1; revisit if validation shows bias.

### 13.2 Assumptions Most Likely Wrong

1. **"Idle thrust on approach"** — Some types use higher idle (A380, B752). Could underestimate by 2–4 dB.
2. **"Soft ground everywhere"** — Water bodies (Danube, Neusiedler See) have different ground effect.
3. **"Straight-line flight path"** — Curved RNP/RNAV approaches not captured.
4. **"60s polling is sufficient"** — OK for L_Amax, poor for SEL, marginal for L_eq.
5. **"Category field is accurate"** — Trust ICAO type code over category field.

### 13.3 Trade-offs: Accuracy vs Complexity

| Feature | Accuracy Gain | Complexity | Decision |
|---------|:---:|:---:|----------|
| Frequency-band computation | +1 dB | High | Skip |
| Terrain-aware ground reflection | +1–2 dB | High | Skip |
| Wind/meteo correction | ±1 dB | Medium | Skip v1 |
| Per-engine-model corrections | +0.5 dB | Medium | Skip |
| Thrust estimation from V/S | +1 dB | Low | **Include** |
| Speed correction | +1–2 dB | Low | **Include** |
| Type-specific L_ref | +2 dB | Low | **Include** |
| Phase-dependent L_ref | +3–5 dB | Low | **Include** |
| Atmospheric absorption | +2–4 dB | Low | **Include** |

**Conclusion:** All "low complexity, high gain" features included. Omitted features deferred to v2 if validation shows systematic errors.

---

## Appendix A: Worked Example

**Scenario:** Airbus A320-200 on final approach to RWY 16, passing 450 m (1,500 ft) altitude, 2.0 km horizontal distance from Mannersdorf center.

**Given:**
- Type: `A320`, Category: `M_NB`, Phase: `FAPP`
- h = 450 m, d_h = 2000 m, V = 72 m/s, v_s = −5 m/s
- Installation: underwing

**Step 1 — Slant distance:**
```
d_slant = √(2000² + 450²) = √(4,000,000 + 202,500) = 2050 m
```

**Step 2 — Reference level:**
```
L_ref = REFERENCE_TABLE[FAPP][M_NB] = 60 dBA
```

**Step 3 — Geometric spreading:**
```
A_geom = 20 × log₁₀(2050/1000) = 20 × 0.3118 = 6.24 dB
```

**Step 4 — Atmospheric absorption:**
```
A_atm = 0.003 × 2050 = 6.15 dB
```

**Step 5 — Ground reflection:**
```
A_ground = 3 × (1 − exp(−2000/(10×450)))
         = 3 × (1 − exp(−0.4444))
         = 3 × (1 − 0.6412) = 1.08 dB
```

**Step 6 — Lateral attenuation:**
```
sin_theta = 450/2050 = 0.2195
Since 0.2195 < 0.3:
A_lateral = 2 × (1 − 0.2195/0.3) = 2 × 0.2683 = 0.54 dB
```

**Step 7 — Speed correction:**
```
A_speed = −7 × 10 × log₁₀(72/70) = −7 × 10 × 0.01226 = −0.86 dB
```
(Faster than reference → negative correction → adds noise; applied as +A_speed in formula)

**Step 8 — Type & installation corrections:**
```
ΔL_type = 0 dB (A320 is reference)
ΔL_inst = 0 dB (underwing is reference)
A_thrust = 0 (not CLIMBOUT)
```

**Step 9 — Final L_Amax:**
```
L_Amax = L_ref − A_geom − A_atm + ΔL_type + ΔL_inst − A_ground − A_lateral + A_speed − A_thrust
       = 60 − 6.24 − 6.15 + 0 + 0 − 1.08 − 0.54 + (−0.86) − 0
       = 60 − 14.87
       = 45.1 dBA
```

**Interpretation:** 45 dBA is clearly audible above typical daytime background (~35–40 dBA in rural Austria), consistent with experience of hearing A320 approaches over Mannersdorf. Within the ±4 dB uncertainty band, the true value is likely 41–49 dBA.

**Comparison with old model:**
```
Old: L_est = 80 − 20 × log₁₀(2.05/0.3) = 80 − 20 × log₁₀(6.833) = 80 − 16.69 = 63.3 dBA
New: L_Amax = 45.1 dBA
```
The old model over-estimated by **18 dB** — primarily because it used an incorrect reference (80 dBA at 300 m is far too high for an approach scenario) and lacked atmospheric absorption (6 dB at this distance).

---

## Appendix B: Standards and References

### Standards

| Reference | Scope |
|-----------|-------|
| **ICAO Annex 16, Vol. I** | Aircraft noise certification standards and measurement procedures |
| **ECAC Doc 29, 4th Ed. (2016)** | Standard method for computing noise contours around civil airports |
| **ISO 9613-1:1993** | Attenuation of sound during propagation outdoors — Part 1: Calculation of atmospheric absorption |
| **ISO 9613-2:1996** | Part 2: General method of calculation for outdoor sound propagation |
| **SAE ARP 866A** | Standard Values of Atmospheric Absorption Versus Frequency and Distance for Acoustical Measurements |
| **EU Directive 2002/49/EC** | Environmental Noise Directive (L_den, L_night definitions) |
| **ICAO Doc 9911** | Report on Noise from Civil Aircraft in Flight |

### Research Papers

| Reference | Relevance |
|-----------|----------|
| Dobrzynski, W. (2010). "Almost 40 Years of Airframe Noise Research." AIAA | Comprehensive airframe noise review |
| Schäffer et al. (2012). "FLULA2 aircraft noise model." | Swiss operational noise model; validation methodology |
| Bertsch et al. (2014). "Aircraft noise prediction in ATM." | State of the art in noise prediction |
| Huff, D. (2012). "Aircraft noise prediction: state of the art." NASA | NASA review of prediction methods |
| Nijboer, H. (2014). "Aircraft noise levels around airports." | Empirical validation of modeled levels |

### Databases

| Source | Access | Data |
|--------|--------|------|
| **ANP Database** (ECAC) | Restricted (license) | NPD tables for certified types |
| **EASA TCDS** | Free (EASA website) | Certification noise levels per type |
| **FAA AEDT** | Free (research use) | Default noise/performance data |
| **ICAO Aircraft Type Designators** | Free | ICAO type code mapping |

### Key Data Points Used

| Aircraft | Certification Approach (dB) | Certification Flyover (dB) | Source |
|----------|:---:|:---:|--------|
| A320-200 (CFM56-5B) | 72.4 | 82.1 | EASA TCDS EASA.A.064 |
| A321-200 (CFM56-5B) | 73.2 | 83.5 | EASA TCDS EASA.A.064 |
| B737-800 (CFM56-7B) | 72.0 | 82.5 | FAA TCDS A16NM |
| B777-300ER (GE90-115B) | 77.8 | 88.2 | FAA TCDS T00001SE |
| A380-800 (GP7200) | 78.2 | 89.1 | EASA TCDS EASA.A.110 |
| B787-9 (GEnx-1B) | 74.6 | 83.8 | FAA TCDS T00035SE |
| CRJ-200 (CF34-3B1) | 74.1 | 84.3 | FAA TCDS A21NM |
| E190 (CF34-10E) | 72.8 | 82.0 | FAA TCDS A32NM |
| DH8-Q400 (PW150A) | 76.4 | 85.2 | TC Canada TCCA |

*Note: Certification values are at standardized measurement points (approach: 2 km from threshold at 120 m; flyover: 6.5 km from start of roll at ~300 m). These are not directly comparable to our 1 km slant reference — the §3.3 values are back-corrected from these using the same spreading/absorption model.*