interface Props<T extends string> {
    label: string;
    tabs: readonly { id: T; label: string }[];
    active: T;
    onChange: (tab: T) => void;
}

/** The panel's underlined tab bar. */
export default function Tabs<T extends string>({ label, tabs, active, onChange }: Props<T>) {
    return (
        <div role='tablist' aria-label={label} className='ofs:mb-6 ofs:flex ofs:gap-2 ofs:overflow-x-auto ofs:border-b ofs:border-border ofs:text-sm'>
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type='button'
                    role='tab'
                    aria-selected={tab.id === active}
                    onClick={() => onChange(tab.id)}
                    className={
                        tab.id === active
                            ? 'ofs:shrink-0 ofs:px-3 ofs:py-2 ofs:text-foreground ofs:shadow-navigation-active'
                            : 'ofs:shrink-0 ofs:px-3 ofs:py-2 ofs:text-muted-foreground ofs:transition-colors ofs:hover:text-foreground'
                    }
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
