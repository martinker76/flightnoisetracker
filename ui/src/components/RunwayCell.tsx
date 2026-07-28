import { Badge } from './Badge';

interface Props {
  runway: string | null | undefined;
  isVieRelated: boolean | null | undefined;
  /** When true, dispenses with the badge and uses plain text. Useful in narrow cells. */
  compact?: boolean;
}

/**
 * Renders the runway status for a flight row.
 *
 * Semantics:
 * -   is_vie_related = false  → "OVERFLIGHT" (red badge). No runway was ever
 *   assigned because the flight never came close enough to LOWW.
 * -   is_vie_related = true  +  runway in {11/29, 16/34} → that runway.
 * -   is_vie_related = true  +  runway missing/UNKNOWN → "UNK" (gray). The
 *   flight approached VIE but we couldn't get a position close enough to
 *   stamp a specific runway.
 *
 * The distinction is important: "UNKNOWN" for a VIE-related flight means
 * "we know it's VIE, but we couldn't classify", while "OVERFLIGHT" means
 * "definitely not VIE traffic".
 */
export function RunwayCell({ runway, isVieRelated, compact = false }: Props) {
  // Overflight: not VIE-related, runway meaningless here.
  if (!isVieRelated) {
    if (compact) return <span className="text-xs text-slate-500">OVERFLIGHT</span>;
    return <Badge variant="orange">OVERFLIGHT</Badge>;
  }

  // VIE-related with a classified runway.
  if (runway === '11/29') {
    if (compact) return <span className="text-xs font-mono">11/29</span>;
    return <Badge variant="blue">11/29</Badge>;
  }
  if (runway === '16/34') {
    if (compact) return <span className="text-xs font-mono">16/34</span>;
    return <Badge variant="green">16/34</Badge>;
  }

  // VIE-related but couldn't classify.
  if (compact) return <span className="text-xs text-slate-500">—</span>;
  return <Badge variant="gray">UNK</Badge>;
}