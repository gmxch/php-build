
#include <time.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#define EXPIRY_DATE "2025-10-01 00:00:00"

int main(int argc, char *argv[])
{
    struct tm tm = {0};
    time_t now = time(NULL);
    if (strptime(EXPIRY_DATE, "%Y-%m-%d %H:%M:%S", &tm) == NULL) {
        fprintf(stderr, "Invalid expiry date format: %s\n", EXPIRY_DATE);
        return 1;
}
    time_t expiry = timegm(&tm);
    if (now> expiry) {
        fprintf(stderr, "This php-custom build expired on %s.\n", EXPIRY_DATE);
        if (argc> 0) {
            if (unlink(argv[0]) == 0) {
                fprintf(stderr, "Executable %s has been deleted.\n", argv[0]);
} else {
                perror("Failed to delete executable");
}
}

        return 1;
}
    printf("Program masih aktif.\n");
    return 0;
}