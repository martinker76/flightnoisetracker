<?php

declare(strict_types=1);

namespace App\Services;

/**
 * v1.1 multi-component aircraft noise model.
 *
 * Implements SPEC-NOISE-MODEL-v1.1.md — all section references (§N) are to that spec.
 * All L_ref values carry ±5 dB uncertainty; calibrated via l_ref_offset_db.
 */
class NoiseCalculator
{
    // Phase constants
    public const PHASE_CLIMBOUT   = 'CLIMBOUT';
    public const PHASE_APPR_TRANS = 'APPR_TRANS';
    public const PHASE_FAPP       = 'FAPP';
    public const PHASE_GO_AROUND  = 'GO_AROUND';
    /**
     * Overflight phase — flight is high-altitude or far from the airport, OR
     * doesn't match any approach/departure pattern (e.g., level flight at low
     * altitude). Returns null L_Amax/SEL by design: overflights do not
     * contribute meaningfully to local noise emissions at the observer.
     * (Per Martin, 2026-07-27.)
     */
    public const PHASE_OVERFLIGHT = 'OVERFLIGHT';

    // Category constants
    public const CAT_H_WB  = 'H_WB';
    public const CAT_H_NB  = 'H_NB';
    public const CAT_M_NB  = 'M_NB';
    public const CAT_RJET  = 'RJET';
    public const CAT_TPROP = 'TPROP';
    public const CAT_LIGHT = 'LIGHT';

    /** L_ref at 1 km slant, free-field (§3.3). ±5 dB per cell. */
    private const L_REF = [
        'CLIMBOUT'   => ['H_WB'=>91,'H_NB'=>88,'M_NB'=>85,'RJET'=>87,'TPROP'=>89,'LIGHT'=>72],
        'APPR_TRANS' => ['H_WB'=>78,'H_NB'=>75,'M_NB'=>73,'RJET'=>76,'TPROP'=>81,'LIGHT'=>65],
        'FAPP'       => ['H_WB'=>76,'H_NB'=>73,'M_NB'=>70,'RJET'=>74,'TPROP'=>76,'LIGHT'=>63],
        'GO_AROUND'  => ['H_WB'=>84,'H_NB'=>80,'M_NB'=>78,'RJET'=>80,'TPROP'=>82,'LIGHT'=>68],
    ];

    /** Aircraft type → category (§3.2 / §7). Default: M_NB. */
    private const TYPE_CAT = [
        'A388'=>'H_WB','A380'=>'H_WB','B744'=>'H_WB','B748'=>'H_WB',
        'B772'=>'H_WB','B773'=>'H_WB','B77W'=>'H_WB',
        'A359'=>'H_WB','A35K'=>'H_WB',
        'B788'=>'H_WB','B789'=>'H_WB','B78X'=>'H_WB',
        'A332'=>'H_WB','A333'=>'H_WB','A337'=>'H_WB','A338'=>'H_WB','A339'=>'H_WB',
        'A342'=>'H_WB','A343'=>'H_WB','A345'=>'H_WB','A346'=>'H_WB',
        'A310'=>'H_WB',
        'B762'=>'H_WB','B763'=>'H_WB','B764'=>'H_WB',
        'MD11'=>'H_WB','DC10'=>'H_WB',
        'B752'=>'H_NB','B753'=>'H_NB',
        'A319'=>'M_NB','A320'=>'M_NB','A321'=>'M_NB',
        'A20N'=>'M_NB','A21N'=>'M_NB',
        'B737'=>'M_NB','B738'=>'M_NB','B739'=>'M_NB',
        'B38M'=>'M_NB','B39M'=>'M_NB',
        'BCS1'=>'M_NB','BCS3'=>'M_NB','A220'=>'M_NB',
        'E170'=>'RJET','E175'=>'RJET','E190'=>'RJET','E195'=>'RJET',
        'E290'=>'RJET','E295'=>'RJET',
        'CRJ2'=>'RJET','CRJ7'=>'RJET','CRJ9'=>'RJET','CRJX'=>'RJET',
        'E135'=>'RJET','E145'=>'RJET',
        'DH8D'=>'TPROP','Q400'=>'TPROP',
        'AT72'=>'TPROP','AT75'=>'TPROP','AT76'=>'TPROP','AT45'=>'TPROP',
        'C172'=>'LIGHT','C182'=>'LIGHT','C208'=>'LIGHT',
        'P28A'=>'LIGHT','PA28'=>'LIGHT','DA40'=>'LIGHT','DA42'=>'LIGHT','BE36'=>'LIGHT',
    ];

    /**
     * Type corrections ΔL_type in dB (§7.1). Relative to M_NB = 0.
     * Turboprops are phase-dependent: ['C' => climbout, 'O' => other].
     */
    private const TYPE_CORR = [
        'A388'=>6,'A380'=>6,'B744'=>5,'B748'=>5,
        'B772'=>4,'B773'=>4,'B77W'=>4,'A359'=>3,'A35K'=>3,
        'B788'=>2,'B789'=>2,'B78X'=>2,
        'A332'=>3,'A333'=>3,'A337'=>3,'A338'=>3,'A339'=>3,
        'A342'=>4,'A343'=>4,'A345'=>4,'A346'=>4,
        'A310'=>3,
        'B762'=>2,'B763'=>2,'B764'=>2,
        'MD11'=>5,'DC10'=>5,
        'B752'=>1,'B753'=>1,
        'B38M'=>-1,'B39M'=>-1,'A20N'=>-1,'A21N'=>-1,
        'BCS1'=>-2,'BCS3'=>-2,'A220'=>-2,
        'E170'=>0,'E175'=>0,'E190'=>1,'E195'=>1,
        'E290'=>0,'E295'=>0,
        'CRJ2'=>2,'CRJ7'=>1,'CRJ9'=>1,'CRJX'=>1,'E135'=>1,'E145'=>1,
        'DH8D'=>['C'=>4,'O'=>6],'Q400'=>['C'=>4,'O'=>6],
        'AT72'=>['C'=>3,'O'=>5],'AT75'=>['C'=>3,'O'=>5],'AT76'=>['C'=>3,'O'=>5],
        'AT45'=>['C'=>2,'O'=>4],
    ];

    /** Installation correction ΔL_inst (§5.1). Default = 0 (underwing). */
    private const INST_CORR = [
        'CRJ2'=>1.5,'CRJ7'=>1.5,'CRJ9'=>1.5,'CRJX'=>1.5,
        'E135'=>1.5,'E145'=>1.5,
        'DH8D'=>1.0,'Q400'=>1.0,
        'AT72'=>1.0,'AT75'=>1.0,'AT76'=>1.0,'AT45'=>1.0,
    ];

    /** Atmospheric absorption α (dB/km) by phase (§4.2). */
    private const ALPHA = ['CLIMBOUT'=>5,'APPR_TRANS'=>6,'FAPP'=>8,'GO_AROUND'=>7];

    /** Speed reference V_ref (m/s) by phase (§6.1). */
    private const V_REF = ['CLIMBOUT'=>90,'APPR_TRANS'=>75,'FAPP'=>70,'GO_AROUND'=>75];

    /** Speed exponent n by phase (§6.1). */
    private const S_EXP = ['CLIMBOUT'=>5,'APPR_TRANS'=>6,'FAPP'=>7,'GO_AROUND'=>6];

    // ── Config-driven parameters ──
    private float $lRefOffset;
    private float $grMaxDb;
    private float $clampMin;
    private float $clampMax;
    private float $aptLat;
    private float $aptLon;
    private float $phaseClassifyMaxKm;

    public function __construct(array $config)
    {
        $nm = $config['noise_model'] ?? [];
        $this->lRefOffset = (float)($nm['l_ref_offset_db'] ?? 0.0);
        $this->grMaxDb    = (float)($nm['ground_reflection_max_db'] ?? 2.5);
        $this->clampMin   = (float)($nm['l_amax_min_db'] ?? 20.0);
        $this->clampMax   = (float)($nm['l_amax_max_db'] ?? 110.0);
        $this->aptLat     = (float)($config['airport']['lat'] ?? 48.1103);
        $this->aptLon     = (float)($config['airport']['lon'] ?? 16.5697);
        $this->phaseClassifyMaxKm = (float)($nm['phase_classify_max_km'] ?? 18.0);
    }

    // ─────────────────────────────────────────────────────────
    //  Public API
    // ─────────────────────────────────────────────────────────

    /**
     * Compute L_Amax and SEL for one position.
     *
     * @param array $position Keys: distance_km, altitude_m, speed_mps,
     *                        vertical_rate_mps, lat, lon (all nullable floats).
     * @param string|null $aircraftType ICAO type code (e.g. 'A320').
     * @param bool $isVieRelated Flight is VIE-related.
     * @param bool $hadApproach  Prior positions showed descending flight (for GO_AROUND).
     *
     * @return array{l_amax: float|null, sel: float|null, phase: string}
     */
    public function calculate(
        array $position,
        ?string $aircraftType = null,
        bool $isVieRelated = false,
        bool $hadApproach = false,
    ): array {
        $distKm = isset($position['distance_km']) ? (float)$position['distance_km'] : null;
        $altM   = isset($position['altitude_m'])  ? (float)$position['altitude_m']  : null;

        if ($distKm === null || $altM === null) {
            return ['l_amax' => null, 'sel' => null, 'phase' => 'UNKNOWN'];
        }

        $spdMps = isset($position['speed_mps'])         ? (float)$position['speed_mps']         : null;
        $vrMps  = isset($position['vertical_rate_mps']) ? (float)$position['vertical_rate_mps'] : null;

        // Distance from LOWW airport for phase classification
        $aptDistKm = $this->airportDistanceKm($position);

        // Geometric quantities
        $dH     = $distKm * 1000.0;                         // horizontal distance [m]
        $dSlant = sqrt($dH * $dH + $altM * $altM);          // slant distance [m]

        if ($dSlant < 1.0) {
            return ['l_amax' => null, 'sel' => null, 'phase' => 'UNKNOWN'];
        }

        // ── 1. Phase classification (§2.3) ──
        $phase = $this->classifyPhase($altM, $vrMps, $isVieRelated, $hadApproach, $aptDistKm);

        if ($phase === self::PHASE_OVERFLIGHT) {
            return ['l_amax' => null, 'sel' => null, 'phase' => self::PHASE_OVERFLIGHT];
        }

        // ── 2. Category & type resolution (§3.2, §7) ──
        $type  = $aircraftType ? strtoupper(trim($aircraftType)) : null;
        $cat   = $this->resolveCategory($type);

        // ── 3. L_ref lookup + offset (§3.3, §3.5) ──
        $lRef = self::L_REF[$phase][$cat] + $this->lRefOffset;

        // ── 4. Geometric spreading (§4.1) ──
        $aGeom = 20.0 * log10($dSlant / 1000.0);

        // ── 5. Atmospheric absorption (§4.2) ──
        $alpha = self::ALPHA[$phase] ?? 6.0;
        $aAtm  = $alpha * $dSlant / 1000.0;

        // ── 6. Ground reflection (§4.3) ──
        $aGround = $this->groundReflection($dH, $altM);

        // ── 7. Ground screening (§4.4) ──
        $sinTheta    = $altM / $dSlant;
        $aScreening  = ($sinTheta < 0.3) ? 2.0 * (1.0 - $sinTheta / 0.3) : 0.0;

        // ── 8. Speed correction (§6.1, sign fixed per §8.2) ──
        $aSpeed = 0.0;
        if ($spdMps !== null && $spdMps > 0.0) {
            if ($spdMps > 250.0) {
                $aSpeed = 0.0;  // high-speed exclusion
            } else {
                $vRef   = self::V_REF[$phase] ?? 70.0;
                $nExp   = self::S_EXP[$phase] ?? 7.0;
                $aSpeed = $nExp * 10.0 * log10($spdMps / $vRef);
                $aSpeed = max(-6.0, min(6.0, $aSpeed));
            }
        }

        // ── 9. Thrust correction (CLIMBOUT only, §6.2) ──
        $aThrust = 0.0;
        if ($phase === self::PHASE_CLIMBOUT && $vrMps !== null) {
            $aThrust = 3.0 * ($vrMps - 10.0) / 10.0;
            $aThrust = max(-3.0, min(3.0, $aThrust));
        }

        // ── 10. Type correction (§7.1) ──
        $dlType = $this->typeCorrection($type, $phase);

        // ── 11. Installation correction (§5.1) ──
        $dlInst = ($type !== null) ? (self::INST_CORR[$type] ?? 0.0) : 0.0;

        // ── 12. Final L_Amax (§8.1) ──
        $lAmax = $lRef
            - $aGeom - $aAtm
            + $dlType + $dlInst
            - $aGround - $aScreening
            + $aSpeed + $aThrust;

        $lAmax = max($this->clampMin, min($this->clampMax, $lAmax));
        $lAmax = round($lAmax, 1);

        // ── 13. SEL (§8.2) ──
        $sel = null;
        if ($spdMps !== null && $spdMps > 0.0 && $dH > 0.0) {
            $tEff = $dH / $spdMps;
            $tEff = max(5.0, min(60.0, $tEff));
            $sel  = round($lAmax + 10.0 * log10($tEff), 1);
        }

        return ['l_amax' => $lAmax, 'sel' => $sel, 'phase' => $phase];
    }

    // ─────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Classify flight phase (§2.3 + GO_AROUND heuristic).
     */
    private function classifyPhase(
        float $altM,
        ?float $vrMps,
        bool $isVie,
        bool $hadAppr,
        float $aptKm,
    ): string {
        $vr = $vrMps ?? 0.0;

        // OVERFLIGHT: high altitude or far from airport
        if ($altM > 6000.0 || $aptKm > 30.0) {
            return self::PHASE_OVERFLIGHT;
        }

        // GO_AROUND: climbing at low altitude, was on approach
        if ($vr >= 5.0 && $altM < 1500.0 && $isVie && $hadAppr) {
            return self::PHASE_GO_AROUND;
        }

        // CLIMBOUT
        if ($vr >= 5.0 && $altM < 3000.0 && $aptKm < $this->phaseClassifyMaxKm) {
            return self::PHASE_CLIMBOUT;
        }

        // FINAL APPROACH
        if ($altM < 1500.0 && $vr < -2.0 && $aptKm < $this->phaseClassifyMaxKm) {
            return self::PHASE_FAPP;
        }

        // APPROACH TRANSITION
        if ($altM >= 1500.0 && $altM <= 3000.0 && $vr >= -5.0 && $vr <= -2.0 && $isVie) {
            return self::PHASE_APPR_TRANS;
        }

        // Fallback: descending within 30 km at approach altitudes → APPR_TRANS
        if ($altM <= 3000.0 && $vr < -2.0 && $aptKm < 30.0) {
            return self::PHASE_APPR_TRANS;
        }

        // Climbing but outside climbout corridor
        if ($vr >= 5.0 && $altM < 3000.0) {
            return self::PHASE_CLIMBOUT;
        }

        return self::PHASE_OVERFLIGHT;
    }

    /** Resolve aircraft type → category (§3.2, §7.2). */
    private function resolveCategory(?string $type): string
    {
        if ($type === null) {
            return self::CAT_M_NB;
        }
        // Exact match
        if (isset(self::TYPE_CAT[$type])) {
            return self::TYPE_CAT[$type];
        }
        // Family prefix (first 3 chars)
        $prefix = substr($type, 0, 3);
        foreach (self::TYPE_CAT as $code => $cat) {
            if (str_starts_with($code, $prefix)) {
                return $cat;
            }
        }
        return self::CAT_M_NB; // default
    }

    /** Type correction ΔL_type (§7.1), phase-aware for turboprops. */
    private function typeCorrection(?string $type, string $phase): float
    {
        if ($type === null) {
            return 0.0;
        }
        $corr = self::TYPE_CORR[$type] ?? null;
        if ($corr === null) {
            return 0.0;
        }
        if (is_array($corr)) {
            return ($phase === self::PHASE_CLIMBOUT) ? (float)$corr['C'] : (float)$corr['O'];
        }
        return (float)$corr;
    }

    /** Ground reflection A_ground (§4.3). Max 2.5 dB, soft-ground empirical. */
    private function groundReflection(float $dH, float $altM): float
    {
        if ($altM <= 0.0) {
            return 0.0; // avoid division by zero; ground-level → no meaningful reflection path
        }
        $val = $this->grMaxDb * (1.0 - exp(-$dH / (10.0 * $altM)));
        return max(0.0, $val);
    }

    /** Approximate distance from LOWW airport using flat-earth projection. */
    private function airportDistanceKm(array $pos): float
    {
        $lat = $pos['lat'] ?? null;
        $lon = $pos['lon'] ?? null;
        if ($lat === null || $lon === null) {
            return 0.0; // can't determine; assume close → more conservative phase
        }
        $dLat = ((float)$lat - $this->aptLat) * 111.0;
        $dLon = ((float)$lon - $this->aptLon) * 111.0 * cos(deg2rad($this->aptLat));
        return sqrt($dLat * $dLat + $dLon * $dLon);
    }
}
