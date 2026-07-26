/**
 * Question-mark icon with hover tooltip, for inline explanations.
 */
interface Props {
  text: string;
}

export function TooltipIcon({ text }: Props) {
  return (
    <span className="relative group inline-flex items-center cursor-help">
      <span className="ml-1 inline-flex items-center justify-center w-4 h-4 rounded-full
        bg-slate-300 dark:bg-slate-600
        text-slate-600 dark:text-slate-300
        text-[10px] font-bold leading-none select-none">
        ?
      </span>
      <span className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2
        px-2.5 py-1.5 rounded-md
        text-xs leading-tight text-white
        bg-gray-800 dark:bg-gray-900
        shadow-lg
        opacity-0 group-hover:opacity-100
        transition-opacity duration-150
        whitespace-nowrap z-20
        pointer-events-none
        max-w-[260px] sm:max-w-none">
        {text}
      </span>
    </span>
  );
}
