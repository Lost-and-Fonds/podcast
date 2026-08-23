# Dependency decisions

Podcast uses XMLWriter and PHP date/URL facilities. No RSS framework is used: precise namespace control and safe escaping are the only required feed features. ffmpeg is an external helper payload owned by the release artifact, not a PHP wrapper or a core dependency.
