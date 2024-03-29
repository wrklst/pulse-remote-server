cat /proc/meminfo | grep MemTotal | grep -E -o '[0-9]+'
cat /proc/meminfo | grep MemAvailable | grep -E -o '[0-9]+'
top -bn1 | grep -E '^(%Cpu|CPU)' | awk '{ print $2 + $4 }'
df / | awk 'NR==2 {print $3 "\n" $4 }'